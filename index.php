<?php
/**
 * KRAFORD DOCS v10.5 - FIXED SAVE/DELETE BUG
 * ✅ Ispravljeno ugnježđivanje formi
 * ✅ Dodatno logovanje akcija
 * ✅ Bolje validacije
 */

// --- KONFIGURACIJA ---
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
$log_file = __DIR__ . '/kraford_errors.log';
ini_set('error_log', $log_file);

// --- GLOBALNE PROMENLJIVE ---
$db_file = __DIR__ . '/kraford_docs.json';
$toast = null;
$session_initialized = false;

// --- INICIJALIZACIJA SESIJE ---
try {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
        $session_initialized = true;
    }
} catch (Exception $e) {
    error_log('SESSION ERROR: ' . $e->getMessage());
}

// --- CSRF PROTECTION ---
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// --- FUNKCIJE ZA RAD SA BAZOM ---
function get_db($path) {
    try {
        if (!file_exists($path)) {
            error_log("DB FILE NOT FOUND: $path");
            return [];
        }
        
        $data = file_get_contents($path);
        if ($data === false) {
            error_log("FAILED TO READ DB FILE: $path");
            return [];
        }
        
        $decoded = json_decode($data, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log("JSON DECODE ERROR (" . json_last_error() . "): " . json_last_error_msg());
            return [];
        }
        
        if (!is_array($decoded)) {
            error_log("DB DECODED IS NOT AN ARRAY");
            return [];
        }
        
        return $decoded;
    } catch (Exception $e) {
        error_log("GET_DB ERROR: " . $e->getMessage());
        return [];
    }
}

function save_db($path, $data) {
    try {
        if (!is_array($data)) {
            error_log("SAVE_DB: DATA IS NOT AN ARRAY");
            return false;
        }
        
        $json = json_encode(array_values($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            $error = json_last_error_msg() . " (Code: " . json_last_error() . ")";
            error_log("SAVE_DB: JSON ENCODE FAILED - $error");
            return false;
        }
        
        json_decode($json);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $error = json_last_error_msg() . " (Code: " . json_last_error() . ")";
            error_log("SAVE_DB: GENERATED JSON IS INVALID - $error");
            return false;
        }
        
        if (file_exists($path) && !is_writable($path)) {
            error_log("SAVE_DB: FILE IS NOT WRITABLE - $path");
            return false;
        }
        
        $result = file_put_contents($path, $json, LOCK_EX);
        if ($result === false) {
            error_log("SAVE_DB: FILE PUT CONTENTS FAILED - $path");
            return false;
        }
        
        return true;
    } catch (Exception $e) {
        error_log("SAVE_DB ERROR: " . $e->getMessage());
        return false;
    }
}

// --- AKCIJE - MAIN CONTROLLER ---
try {
    $action = $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) ? $_POST['action'] : null;
    
    if ($action) {
        error_log("ACTION RECEIVED: " . $action);
        
        // CSRF VALIDACIJA
        if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
            throw new Exception("CSRF token validation failed");
        }
        
        $docs = get_db($db_file);
        
        if ($action === 'save') {
            error_log("PROCESSING SAVE ACTION");
            
            $id = $_POST['doc_id'] ?? null;
            $category = trim($_POST['category'] ?? '');
            $title = trim($_POST['title'] ?? '');
            
            if (empty($category)) {
                throw new Exception("Category cannot be empty");
            }
            if (empty($title)) {
                throw new Exception("Title cannot be empty");
            }
            
            $tagsRaw = trim($_POST['tags'] ?? '');
            $tagsArray = array_filter(array_map('trim', preg_split('/[,\s]+/', $tagsRaw)));
            
            $versionHistory = [];
            if ($id) {
                foreach ($docs as $k => $v) {
                    if ($v['id'] === $id) {
                        $versionHistory = $v['versions'] ?? [];
                        $versionHistory[] = [
                            'content' => $v['content'],
                            'saved_at' => $v['updated_at']
                        ];
                        $versionHistory = array_slice($versionHistory, -5);
                        break;
                    }
                }
            }
            
            $payload = [
                'id' => $id ?: 'doc_' . bin2hex(random_bytes(4)),
                'category' => htmlspecialchars($category),
                'title' => htmlspecialchars($title),
                'description' => htmlspecialchars(trim($_POST['description'] ?? '')),
                'content' => $_POST['content'] ?? '',
                'tags' => array_map('htmlspecialchars', $tagsArray),
                'versions' => $versionHistory,
                'updated_at' => date('d.m.Y H:i')
            ];
            
            $modified = false;
            if ($id) {
                foreach ($docs as $k => $v) { 
                    if ($v['id'] === $id) { 
                        $docs[$k] = $payload; 
                        $modified = true; 
                        break; 
                    } 
                }
                if (!$modified) {
                    throw new Exception("Document with ID $id not found for update");
                }
            } else { 
                $docs[] = $payload; 
                $modified = true; 
            }
            
            if ($modified && !save_db($db_file, $docs)) {
                throw new Exception("Failed to save document to database");
            }
            
            $toast = [
                'type' => 'success', 
                'message' => $id ? 'Dokument uspešno ažuriran! 🎉' : 'Nova stranica kreirana! ✨'
            ];
            
        } elseif ($action === 'delete') {
            error_log("PROCESSING DELETE ACTION");
            $id = $_POST['doc_id'] ?? null;
            if (!$id) {
                throw new Exception("Document ID is required for deletion");
            }
            
            $original_count = count($docs);
            $docs = array_filter($docs, function($d) use ($id) {
                return $d['id'] !== $id;
            });
            
            if (count($docs) === $original_count) {
                throw new Exception("Document with ID $id not found");
            }
            
            if (!save_db($db_file, $docs)) {
                throw new Exception("Failed to delete document from database");
            }
            
            $toast = ['type' => 'info', 'message' => 'Dokument obrisan. 🗑️'];
            
        } elseif ($action === 'restore_version') {
            error_log("PROCESSING RESTORE VERSION ACTION");
            $id = $_POST['doc_id'] ?? null;
            $versionIndex = intval($_POST['version_index'] ?? 0);
            
            if (!$id) {
                throw new Exception("Document ID is required for version restoration");
            }
            
            $found = false;
            foreach ($docs as $k => $v) {
                if ($v['id'] === $id && isset($v['versions'][$versionIndex])) {
                    $currentContent = $v['content'];
                    $docs[$k]['content'] = $v['versions'][$versionIndex]['content'];
                    $docs[$k]['versions'][] = [
                        'content' => $currentContent,
                        'saved_at' => date('d.m.Y H:i')
                    ];
                    $docs[$k]['versions'] = array_slice($docs[$k]['versions'], -5);
                    $docs[$k]['updated_at'] = date('d.m.Y H:i');
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                throw new Exception("Document or version not found");
            }
            
            if (!save_db($db_file, $docs)) {
                throw new Exception("Failed to restore version");
            }
            
            $toast = ['type' => 'success', 'message' => 'Verzija vraćena! ⏮️'];
            
        } elseif ($action === 'import') {
            error_log("PROCESSING IMPORT ACTION");
            if (!isset($_FILES['import_file']) || $_FILES['import_file']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("File upload failed");
            }
            
            $content = file_get_contents($_FILES['import_file']['tmp_name']);
            if ($content === false) {
                throw new Exception("Failed to read uploaded file");
            }
            
            $imported = json_decode($content, true);
            if (!is_array($imported)) {
                throw new Exception("Invalid JSON format in uploaded file");
            }
            
            $existing = get_db($db_file);
            $existing_ids = array_column($existing, 'id');
            $imported_count = 0;
            
            foreach ($imported as $doc) {
                if (!in_array($doc['id'], $existing_ids)) {
                    if (!isset($doc['title']) || !isset($doc['content'])) {
                        continue;
                    }
                    $existing[] = $doc;
                    $imported_count++;
                }
            }
            
            if ($imported_count > 0 && !save_db($db_file, $existing)) {
                throw new Exception("Failed to save imported documents");
            }
            
            $toast = [
                'type' => 'success', 
                'message' => "$imported_count dokumenta uspešno importovano! 📥"
            ];
        }
        
        if ($toast) {
            $_SESSION['toast'] = $toast;
            $redirectUrl = $_SERVER['PHP_SELF'];
            if ($action === 'save' && isset($payload['id'])) {
                $redirectUrl .= "#doc_{$payload['id']}";
            }
            error_log("REDIRECTING TO: " . $redirectUrl);
            header("Location: " . $redirectUrl);
            exit;
        }
        
    } else {
        $docs = get_db($db_file);
    }

} catch (Exception $e) {
    error_log("ACTION ERROR: " . $e->getMessage());
    $toast = ['type' => 'error', 'message' => 'Greška: ' . $e->getMessage() . ' ❌'];
    if ($session_initialized) {
        $_SESSION['toast'] = $toast;
    }
    
    if (isset($_POST['doc_id']) && $_POST['action'] === 'save') {
        header("Location: " . $_SERVER['PHP_SELF'] . "#doc_" . $_POST['doc_id']);
    } else {
        header("Location: " . $_SERVER['PHP_SELF']);
    }
    exit;
}

// --- PRIKAZ TOAST PORUKE ---
if (isset($_SESSION['toast'])) {
    $toast = $_SESSION['toast'];
    unset($_SESSION['toast']);
}

// --- INICIJALIZACIJA PODATAKA ---
if (!file_exists($db_file)) {
    $init = [
        [
            'id' => 'doc_welcome',
            'category' => 'Sistem',
            'title' => 'Početna Stranica',
            'description' => 'Dobrodošli u vašu optimizovanu bazu znanja.',
            'content' => "# Kraford Docs v10.5 ✨\n\nSve radi kako treba!",
            'tags' => ['sistem', 'početna'],
            'versions' => [],
            'updated_at' => date('d.m.Y H:i')
        ]
    ];
    if (!save_db($db_file, $init)) {
        $toast = [
            'type' => 'error',
            'message' => 'Greška: Ne mogu da kreiram bazu podataka. Proveri dozvole fajla! ❌'
        ];
    } else {
        $docs = $init;
    }
}

// --- PREPARE DATA FOR DISPLAY ---
$grouped = [];
$docs_by_id = [];
$allTags = [];

foreach ($docs as $d) {
    $category = $d['category'] ?? 'Bez kategorije';
    if (!isset($grouped[$category])) {
        $grouped[$category] = [];
    }
    $grouped[$category][] = $d;
    
    $docs_by_id[$d['id']] = $d;
    
    if (!empty($d['tags'])) {
        foreach ($d['tags'] as $tag) {
            $allTags[$tag] = ($allTags[$tag] ?? 0) + 1;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="sr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="description" content="Kraford Docs - Profesionalna dokumentacija sa Markdown podrškom">
    <meta name="theme-color" content="#2563eb">
    <title>Kraford Docs | v10.5 Fixed</title>
    
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=JetBrains+Mono:wght@400;500&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/marked/marked.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/fuse.js@7.0.0/dist/fuse.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/styles/github-dark.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/highlight.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/languages/php.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/languages/javascript.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/languages/python.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/languages/sql.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/languages/bash.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/languages/json.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/languages/css.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/highlight.js/11.9.0/languages/xml.min.js"></script>

    <style>
        :root {
            /* Light Theme */
            --p: #2563eb; --p-dark: #1e40af; --p-soft: #eff6ff;
            --txt: #0f172a; --txt-m: #64748b; --txt-inv: #f8fafc;
            --bg: #ffffff; --bg-alt: #fcfdfe; --bg-code: #0d1117;
            --border: #e2e8f0; --shadow: rgba(0,0,0,0.1);
            --top-h: 70px; --radius: 12px;
            --toast-success: #22c55e; --toast-error: #ef4444; --toast-info: #3b82f6;
            --focus-ring: 0 0 0 3px rgba(37, 99, 235, 0.4);
            --font-base: 16px;
            --line-height: 1.9;
            --max-width: 85ch;
            --spacing-section: 2.5rem;
            --spacing-element: 1.5rem;
        }

        [data-theme="dark"] {
            --txt: #f1f5f9; --txt-m: #94a3b8; --txt-inv: #0f172a;
            --bg: #0f172a; --bg-alt: #1e293b; --bg-code: #0d1117;
            --border: #334155; --p-soft: #1e3a5f; --shadow: rgba(0,0,0,0.4);
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }
        
        html { scroll-behavior: smooth; font-size: var(--font-base); }
        
        body { 
            font-family: 'Plus Jakarta Sans', sans-serif; 
            background: var(--bg); 
            color: var(--txt); 
            display: flex; 
            height: 100vh; 
            overflow: hidden;
            transition: background 0.3s ease, color 0.3s ease;
        }

        /* SKIP LINK za pristupačnost */
        .skip-link {
            position: absolute; top: -40px; left: 0;
            background: var(--p); color: white; padding: 8px 16px;
            z-index: 3000; transition: top 0.2s;
        }
        .skip-link:focus { top: 0; }

        /* FOCUS STYLES - Enhanced for accessibility */
        *:focus-visible {
            outline: 2px solid var(--p);
            outline-offset: 2px;
            box-shadow: var(--focus-ring);
        }

        /* SIDEBAR & RESPONSIVENESS */
        .overlay { 
            display: none; position: fixed; inset: 0; 
            background: rgba(0,0,0,0.4); backdrop-filter: blur(4px); 
            z-index: 998; 
        }
        .overlay.active { display: block; }

        aside.side {
            width: 340px; background: var(--bg-alt); border-right: 1px solid var(--border);
            display: flex; flex-direction: column; z-index: 999;
            transition: transform 0.3s ease;
        }
        
        .side-h { padding: 1.5rem; border-bottom: 1px solid var(--border); }
        
        .logo { 
            display: flex; align-items: center; gap: 10px; 
            font-weight: 800; font-size: 1.25rem; letter-spacing: -0.5px; 
            margin-bottom: 1rem; color: var(--txt);
        }
        .logo-box { 
            background: var(--p); color: white; padding: 6px; 
            border-radius: 8px; display: flex; 
        }
        
        .search-box { position: relative; }
        .search-box input {
            width: 100%; padding: 10px 10px 10px 35px; 
            border-radius: 10px; border: 1px solid var(--border);
            font-size: 0.85rem; outline: none; 
            background: var(--bg); color: var(--txt);
            transition: 0.2s;
        }
        .search-box input:focus { border-color: var(--p); box-shadow: var(--focus-ring); }
        .search-box::before { 
            content: "🔍"; position: absolute; left: 12px; top: 10px; 
            font-size: 0.8rem; opacity: 0.4; 
        }
        
        /* Search Suggestions Dropdown */
        .search-suggestions {
            position: absolute; top: 100%; left: 0; right: 0;
            background: var(--bg); border: 1px solid var(--border);
            border-radius: 10px; margin-top: 5px;
            max-height: 300px; overflow-y: auto;
            display: none; z-index: 1000;
            box-shadow: 0 10px 25px var(--shadow);
        }
        .search-suggestions.active { display: block; }
        .search-suggestion-item {
            padding: 10px 15px; cursor: pointer;
            border-bottom: 1px solid var(--border);
            transition: background 0.2s;
        }
        .search-suggestion-item:last-child { border-bottom: none; }
        .search-suggestion-item:hover { background: var(--p-soft); }
        .search-suggestion-item mark { background: var(--p-soft); color: var(--p); }

        nav.nav { 
            flex: 1; overflow-y: auto; padding: 1rem; 
            scrollbar-width: thin; scrollbar-color: var(--border) transparent;
        }
        nav.nav::-webkit-scrollbar { width: 6px; }
        nav.nav::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }
        
        /* Recent & Favorites Sections */
        .nav-section-title {
            font-size: 0.75rem; font-weight: 700; text-transform: uppercase;
            color: var(--txt-m); margin: 1rem 0 0.5rem 0.5rem;
            letter-spacing: 1px; display: flex; align-items: center; gap: 0.5rem;
        }
        
        /* Collapsible Categories - Accordion Style */
        .nav-cat { 
            font-size: 0.75rem; font-weight: 800; text-transform: uppercase; 
            color: var(--txt-m); margin: 1.2rem 0 0.4rem 0.5rem; 
            letter-spacing: 1px; cursor: pointer; display: flex;
            align-items: center; justify-content: space-between;
            padding: 0.5rem 0.8rem; border-radius: 8px;
            transition: background 0.2s, color 0.2s;
        }
        .nav-cat:hover { background: var(--p-soft); color: var(--p); }
        .nav-cat .toggle-icon { transition: transform 0.3s ease; font-size: 0.7rem; }
        .nav-cat.collapsed .toggle-icon { transform: rotate(-90deg); }
        .nav-cat-wrapper.collapsed .nav-items { display: none; }
        .nav-items { transition: all 0.3s ease; }
        
        .nav-link {
            display: flex; align-items: center; justify-content: space-between;
            padding: 0.7rem 0.8rem; border-radius: 8px; 
            text-decoration: none; color: var(--txt);
            font-size: 0.9rem; font-weight: 500; cursor: pointer; 
            transition: all 0.2s ease; margin-bottom: 2px;
            position: relative; overflow: hidden;
        }
        .nav-link::before {
            content: ''; position: absolute; left: 0; top: 0; bottom: 0;
            width: 0; background: var(--p-soft); transition: width 0.2s;
            z-index: -1;
        }
        .nav-link:hover::before { width: 100%; }
        .nav-link:hover { color: var(--p); transform: translateX(3px); }
        .nav-link.active { 
            background: var(--p-soft); color: var(--p); 
            font-weight: 700; border-left: 3px solid var(--p); 
        }
        .nav-link .fav-star { 
            font-size: 1rem; opacity: 0.3; transition: 0.2s; 
        }
        .nav-link .fav-star.active { opacity: 1; color: #fbbf24; }
        .nav-link .doc-date {
            font-size: 0.7rem; color: var(--txt-m); margin-left: 0.5rem;
        }

        /* Tags Filter */
        .tags-filter {
            padding: 0.5rem 1rem; border-bottom: 1px solid var(--border);
            display: flex; flex-wrap: wrap; gap: 0.5rem;
        }
        .tag-chip {
            font-size: 0.75rem; padding: 0.3rem 0.8rem;
            background: var(--bg-alt); border: 1px solid var(--border);
            border-radius: 20px; cursor: pointer;
            transition: all 0.2s; color: var(--txt-m);
        }
        .tag-chip:hover, .tag-chip.active {
            background: var(--p); color: white; border-color: var(--p);
            transform: scale(1.05);
        }

        /* VIEWPORT */
        main.view { 
            flex: 1; background: var(--bg); display: flex; 
            flex-direction: column; overflow: hidden; 
        }
        header.top { 
            height: var(--top-h); border-bottom: 1px solid var(--border); 
            display: flex; align-items: center; justify-content: space-between; 
            padding: 0 1.5rem; background: var(--bg);
        }
        
        .hamb { 
            font-size: 1.5rem; cursor: pointer; display: none; 
            background: none; border: none; color: var(--txt);
        }

        .btn {
            padding: 10px 18px; border-radius: 10px; font-weight: 600; font-size: 0.85rem; 
            cursor: pointer; transition: all 0.2s ease; border: 1px solid var(--border); 
            display: inline-flex; align-items: center; gap: 8px;
            background: var(--bg); color: var(--txt);
            position: relative; overflow: hidden;
        }
        .btn:hover { 
            background: var(--bg-alt); 
            transform: translateY(-2px);
            box-shadow: 0 4px 12px var(--shadow);
        }
        .btn:active { transform: translateY(0); }
        .btn-p { background: var(--p); color: white; border: none; }
        .btn-p:hover { 
            background: var(--p-dark); 
            box-shadow: 0 4px 15px rgba(37, 99, 235, 0.4);
        }

        .main { 
            flex: 1; overflow-y: auto; padding: 2.5rem 1.5rem; 
            scroll-behavior: smooth; position: relative;
        }
        article.card { 
            max-width: 900px; margin: 0 auto; width: 100%;
            animation: fadeIn 0.3s ease;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* BREADCRUMBS */
        nav.breadcrumbs {
            display: flex; align-items: center; gap: 0.5rem;
            font-size: 0.85rem; color: var(--txt-m);
            margin-bottom: 1.5rem; padding: 0.5rem 0;
            flex-wrap: wrap;
        }
        nav.breadcrumbs a {
            color: var(--txt-m); text-decoration: none;
            transition: color 0.2s;
        }
        nav.breadcrumbs a:hover { color: var(--p); }
        nav.breadcrumbs span.separator { opacity: 0.5; }
        nav.breadcrumbs span.current { color: var(--txt); font-weight: 600; }

        /* MARKDOWN STYLING - Optimized Typography */
        .md-body { 
            line-height: var(--line-height); 
            font-size: 1.05rem; 
            max-width: var(--max-width);
            margin: 0 auto;
        }
        .md-body h1 { 
            font-size: 2.8rem; font-weight: 800; 
            margin-bottom: 1.5rem; letter-spacing: -1.5px;
            line-height: 1.2;
        }
        .md-body h2 { 
            font-size: 1.8rem; 
            margin: var(--spacing-section) 0 1rem 0; 
            padding-bottom: 0.5rem; 
            border-bottom: 2px solid var(--border); 
        }
        .md-body h3 { 
            font-size: 1.4rem; 
            margin: var(--spacing-section) 0 0.8rem 0; 
        }
        .md-body p { 
            margin-bottom: var(--spacing-element); 
        }
        .md-body a { 
            color: var(--p); text-decoration: none;
            transition: all 0.2s;
            position: relative;
        }
        .md-body a::after {
            content: ''; position: absolute; bottom: -2px; left: 0;
            width: 0; height: 2px; background: var(--p);
            transition: width 0.2s;
        }
        .md-body a:hover::after { width: 100%; }
        .md-body a:hover { color: var(--p-dark); }
        .md-body ul, .md-body ol { 
            margin: 1rem 0 1rem 2rem; 
        }
        .md-body li { 
            margin-bottom: 0.5rem; 
        }
        .md-body blockquote {
            border-left: 4px solid var(--p);
            padding-left: 1.5rem;
            margin: var(--spacing-element) 0;
            color: var(--txt-m);
            font-style: italic;
        }
        
        /* Table Optimization with Scroll Indicator */
        .table-wrap { 
            width: 100%; overflow-x: auto; 
            margin: var(--spacing-element) 0; 
            border: 1px solid var(--border); 
            border-radius: var(--radius);
            position: relative;
        }
        /* Scroll indicator gradient */
        .table-wrap::after {
            content: '';
            position: absolute;
            right: 0; top: 0; bottom: 0;
            width: 40px;
            background: linear-gradient(to right, transparent, var(--bg));
            pointer-events: none;
            opacity: 0;
            transition: opacity 0.2s;
        }
        .table-wrap.scrolled-right::after { opacity: 1; }
        .table-wrap::-webkit-scrollbar { height: 8px; }
        .table-wrap::-webkit-scrollbar-thumb { 
            background: var(--border); 
            border-radius: 4px; 
        }
        table { border-collapse: collapse; width: 100%; background: var(--bg); min-width: 600px; }
        th, td { padding: 14px; text-align: left; border-bottom: 1px solid var(--border); }
        th { background: var(--bg-alt); font-weight: 700; font-size: 0.9rem; }
        tr:hover { background: var(--p-soft); }

        /* Code Blocks with Syntax Highlighting */
        pre { 
            position: relative;
            background: var(--bg-code) !important; 
            color: var(--txt-inv) !important; 
            padding: 1.5rem; 
            border-radius: var(--radius); 
            overflow-x: auto; 
            margin: var(--spacing-element) 0; 
            border: 1px solid var(--border);
            line-height: 1.5;
        }
        /* Scroll indicator for code blocks */
        pre::after {
            content: '⟷';
            position: absolute;
            right: 10px; bottom: 5px;
            font-size: 0.7rem;
            color: var(--txt-m);
            opacity: 0.5;
            pointer-events: none;
        }
        .copy-code-btn {
            position: absolute; top: 10px; right: 10px;
            padding: 6px 12px; background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.2); border-radius: 6px;
            color: var(--txt-inv); font-size: 0.8rem; cursor: pointer;
            transition: all 0.2s;
        }
        .copy-code-btn:hover { 
            background: rgba(255,255,255,0.2); 
            transform: scale(1.05);
        }
        code { font-family: 'JetBrains Mono', monospace; font-size: 0.9rem; }
        :not(pre) > code { 
            background: var(--bg-alt); color: var(--p); 
            padding: 2px 6px; border-radius: 6px; font-weight: 600; 
        }

        /* EDITOR TABS & TOOLBAR */
        .editor-tabs {
            display: flex; gap: 0.5rem; margin-bottom: 0.5rem;
            border-bottom: 1px solid var(--border); padding-bottom: 0.5rem;
        }
        .editor-tab {
            padding: 0.5rem 1rem; border-radius: 8px 8px 0 0;
            cursor: pointer; font-weight: 500; font-size: 0.9rem;
            background: transparent; border: none; color: var(--txt-m);
            transition: all 0.2s;
        }
        .editor-tab:hover { background: var(--p-soft); color: var(--p); }
        .editor-tab.active {
            background: var(--p-soft); color: var(--p);
            font-weight: 600;
        }
        
        /* Markdown Toolbar */
        .markdown-toolbar {
            display: flex; flex-wrap: wrap; gap: 0.3rem;
            padding: 0.5rem; background: var(--bg-alt);
            border: 1px solid var(--border); border-radius: 10px 10px 0 0;
            margin-bottom: 0;
        }
        .toolbar-btn {
            padding: 6px 10px; background: var(--bg);
            border: 1px solid var(--border); border-radius: 6px;
            cursor: pointer; font-size: 0.85rem; color: var(--txt);
            transition: all 0.2s;
        }
        .toolbar-btn:hover { 
            background: var(--p-soft); border-color: var(--p); color: var(--p);
            transform: scale(1.05);
        }
        
        #preview-container {
            display: none; background: var(--bg-alt); 
            border: 1px solid var(--border); border-radius: 0 0 10px 10px;
            padding: 1.5rem; min-height: 350px;
        }
        #preview-container.active { display: block; }
        #f-content.active { display: none; }
        #f-content { 
            border-radius: 0 0 10px 10px; 
            min-height: 350px; 
        }
        
        /* Word Count */
        .word-count {
            font-size: 0.8rem; color: var(--txt-m);
            text-align: right; padding: 0.5rem;
            border-top: 1px solid var(--border);
        }

        /* MODAL */
        .modal-w { 
            position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); 
            backdrop-filter: blur(8px); display: none; 
            align-items: center; justify-content: center; 
            z-index: 2000; padding: 1rem; 
        }
        .modal-w[aria-hidden="false"] { display: flex; }
        .modal-b { 
            background: var(--bg); width: 100%; max-width: 950px; 
            border-radius: 20px; padding: 2rem; 
            box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); 
            max-height: 90vh; overflow-y: auto; 
        }
        .f-control { 
            width: 100%; padding: 12px; border: 1px solid var(--border); 
            border-radius: 10px; font-family: inherit; font-size: 1rem; 
            margin-bottom: 1rem; outline: none; 
            background: var(--bg); color: var(--txt);
            transition: all 0.2s;
        }
        .f-control:focus { border-color: var(--p); box-shadow: var(--focus-ring); }
        textarea.f-control { min-height: 350px; font-family: 'JetBrains Mono', monospace; resize: vertical; }

        /* TOC - Table of Contents */
        .toc-panel {
            position: fixed; right: 2rem; top: 100px;
            width: 250px; background: var(--bg-alt);
            border: 1px solid var(--border); border-radius: 12px;
            padding: 1rem; display: none; z-index: 100;
            max-height: 70vh; overflow-y: auto;
        }
        .toc-panel.active { display: block; }
        .toc-panel h4 { 
            font-size: 0.85rem; font-weight: 700; 
            margin-bottom: 0.8rem; color: var(--txt);
        }
        .toc-list { list-style: none; }
        .toc-list li { margin-bottom: 0.5rem; }
        .toc-list a {
            font-size: 0.85rem; color: var(--txt-m);
            text-decoration: none; display: block;
            padding: 0.3rem 0; transition: all 0.2s;
        }
        .toc-list a:hover { color: var(--p); transform: translateX(5px); }
        .toc-list a.active { color: var(--p); font-weight: 600; }
        .toc-list .toc-h3 { padding-left: 1rem; font-size: 0.8rem; }

        /* BACK TO TOP BUTTON */
        .back-to-top {
            position: fixed; bottom: 2rem; right: 2rem;
            width: 50px; height: 50px;
            background: var(--p); color: white;
            border: none; border-radius: 50%;
            cursor: pointer; font-size: 1.5rem;
            display: none; align-items: center; justify-content: center;
            transition: all 0.3s; z-index: 500;
            box-shadow: 0 4px 15px rgba(37, 99, 235, 0.4);
        }
        .back-to-top.visible { display: flex; }
        .back-to-top:hover { 
            transform: translateY(-5px) scale(1.1); 
            box-shadow: 0 8px 25px rgba(37, 99, 235, 0.5);
        }

        /* NEXT/PREV NAVIGATION */
        .doc-navigation {
            display: flex; justify-content: space-between;
            margin-top: 3rem; padding-top: 2rem;
            border-top: 1px solid var(--border);
        }
        .nav-btn {
            padding: 1rem 1.5rem; border: 1px solid var(--border);
            border-radius: 10px; text-decoration: none;
            color: var(--txt); transition: all 0.2s;
            max-width: 45%;
        }
        .nav-btn:hover { 
            background: var(--p-soft); border-color: var(--p); color: var(--p);
            transform: translateY(-2px);
        }
        .nav-btn.disabled { opacity: 0.5; pointer-events: none; }
        .nav-btn-label { font-size: 0.75rem; color: var(--txt-m); display: block; }
        .nav-btn-title { font-weight: 600; display: block; }

        /* TOAST NOTIFICATIONS */
        #toast-container {
            position: fixed; bottom: 2rem; right: 2rem; z-index: 3000;
            display: flex; flex-direction: column; gap: 0.5rem;
        }
        .toast {
            display: flex; align-items: center; gap: 0.75rem;
            padding: 1rem 1.5rem; border-radius: 12px;
            color: white; font-weight: 500; font-size: 0.95rem;
            box-shadow: 0 10px 25px -5px rgba(0,0,0,0.3);
            animation: slideIn 0.3s ease, fadeOut 0.3s ease 2.7s forwards;
            max-width: 350px;
        }
        .toast.success { background: var(--toast-success); }
        .toast.error { background: var(--toast-error); }
        .toast.info { background: var(--toast-info); }
        @keyframes slideIn {
            from { transform: translateX(120%); opacity: 0; }
            to { transform: translateX(0); opacity: 1; }
        }
        @keyframes fadeOut {
            to { opacity: 0; transform: translateY(10px); }
        }

        /* DARK MODE TOGGLE */
        .theme-toggle {
            background: none; border: none; font-size: 1.3rem;
            cursor: pointer; padding: 0.5rem; border-radius: 8px;
            color: var(--txt); transition: all 0.2s;
        }
        .theme-toggle:hover { 
            background: var(--bg-alt);
            transform: scale(1.1);
        }

        /* INFO CARDS */
        .info-grid {
            margin-top: 3rem; display: grid; 
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); 
            gap: 1.5rem; 
        }
        .info-card {
            padding: 1.5rem; border: 1px solid var(--border); 
            border-radius: 15px; background: var(--bg-alt);
            transition: all 0.2s;
        }
        .info-card:hover {
            transform: translateY(-3px);
            box-shadow: 0 8px 20px var(--shadow);
        }
        .info-card h3 { margin-bottom: 10px; color: var(--txt); }
        .info-card p { font-size: 0.9rem; color: var(--txt-m); }

        /* SKELETON LOADING */
        .skeleton {
            background: linear-gradient(90deg, var(--bg-alt) 25%, var(--border) 50%, var(--bg-alt) 75%);
            background-size: 200% 100%;
            animation: shimmer 1.5s infinite;
            border-radius: 8px;
        }
        .skeleton-line { height: 20px; margin-bottom: 1rem; }
        .skeleton-line.w-75 { width: 75%; }
        .skeleton-line.w-100 { width: 100%; }
        .skeleton-line.w-60 { width: 60%; }
        @keyframes shimmer {
            0% { background-position: 200% 0; }
            100% { background-position: -200% 0; }
        }

        /* KEYBOARD SHORTCUTS MODAL */
        .shortcuts-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem; margin-top: 1rem;
        }
        .shortcut-item {
            display: flex; justify-content: space-between;
            padding: 0.8rem; background: var(--bg-alt);
            border-radius: 8px; border: 1px solid var(--border);
            transition: all 0.2s;
        }
        .shortcut-item:hover {
            transform: translateX(5px);
            border-color: var(--p);
        }
        .shortcut-key {
            background: var(--bg); padding: 0.3rem 0.6rem;
            border-radius: 6px; border: 1px solid var(--border);
            font-family: 'JetBrains Mono', monospace; font-size: 0.85rem;
        }

        /* VERSION HISTORY MODAL */
        .version-list {
            max-height: 300px; overflow-y: auto;
            border: 1px solid var(--border); border-radius: 10px;
            margin: 1rem 0;
        }
        .version-item {
            padding: 1rem; border-bottom: 1px solid var(--border);
            display: flex; justify-content: space-between; align-items: center;
            transition: background 0.2s;
        }
        .version-item:hover { background: var(--p-soft); }
        .version-item:last-child { border-bottom: none; }

        /* FONT SIZE ADJUSTMENT */
        [data-font-size="large"] { --font-base: 18px; }
        [data-font-size="xlarge"] { --font-base: 20px; }

        /* DRAG DROP UPLOAD AREA */
        .drop-zone {
            border: 2px dashed var(--border); border-radius: 10px;
            padding: 2rem; text-align: center; color: var(--txt-m);
            margin-bottom: 1rem; transition: all 0.2s;
            cursor: pointer;
        }
        .drop-zone:hover, .drop-zone.dragover { 
            border-color: var(--p); 
            background: var(--p-soft);
            transform: scale(1.02);
        }

        /* EXPORT/IMPORT SECTION */
        .export-import-section {
            padding: 1rem; border-top: 1px solid var(--border);
            display: flex; gap: 0.5rem; flex-wrap: wrap;
        }
        .export-import-section .btn { font-size: 0.75rem; padding: 8px 12px; }

        @media (max-width: 1024px) {
            aside.side { 
                position: fixed; left: 0; top: 0; height: 100%; 
                transform: translateX(-100%); 
            }
            aside.side.active { transform: translateX(0); }
            .hamb { display: block; }
            #toast-container { right: 1rem; bottom: 1rem; }
            .toc-panel { display: none !important; }
            .back-to-top { bottom: 1rem; right: 1rem; }
        }

        @media (prefers-reduced-motion: reduce) {
            *, *::before, *::after {
                animation-duration: 0.01ms !important;
                animation-iteration-count: 1 !important;
                transition-duration: 0.01ms !important;
                scroll-behavior: auto !important;
            }
        }

        /* PRINT STYLES */
        @media print {
            .side, .top, .btn, .overlay, #toast-container, .back-to-top, .toc-panel, .markdown-toolbar, .editor-tabs, .export-import-section { 
                display: none !important; 
            }
            .view, .main { overflow: visible !important; height: auto !important; }
            .card { max-width: 100% !important; padding: 0 !important; }
            a { text-decoration: none; color: inherit; }
            pre { white-space: pre-wrap; word-wrap: break-word; }
        }

        /* VISUALLY HIDDEN for screen readers */
        .visually-hidden {
            position: absolute; width: 1px; height: 1px;
            padding: 0; margin: -1px; overflow: hidden;
            clip: rect(0, 0, 0, 0); white-space: nowrap; border: 0;
        }

        /* WIKI LINK STYLING */
        .wiki-link {
            color: var(--p);
            text-decoration: none;
            border-bottom: 2px solid var(--p-soft);
            transition: all 0.2s;
        }
        .wiki-link:hover {
            background: var(--p-soft);
            border-bottom-color: var(--p);
        }
        .wiki-link.broken {
            color: var(--toast-error);
            border-bottom-color: var(--toast-error);
        }
    </style>
</head>
<body>

    <a href="#main-content" class="skip-link">Preskoči na sadržaj</a>

    <div class="overlay" id="overlay" onclick="toggleMeni()" aria-hidden="true"></div>

    <aside class="side" id="sidebar" role="navigation" aria-label="Glavna navigacija">
        <header class="side-h">
            <div class="logo">
                <div class="logo-box" aria-hidden="true">
                    <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <path d="M12 2L2 7l10 5 10-5-10-5zM2 17l10 5 10-5M2 12l10 5 10-5"></path>
                    </svg>
                </div>
                Kraford<span>Docs</span>
            </div>
            <div class="search-box" role="search">
                <label for="searchInput" class="visually-hidden">Pretraga dokumenata</label>
                <input type="search" id="searchInput" placeholder="Pretraga... (Ctrl+K)" onkeyup="handleSearch()" aria-label="Pretraži dokumente" autocomplete="off">
                <div class="search-suggestions" id="searchSuggestions" role="listbox"></div>
            </div>
        </header>
        
        <div class="tags-filter" id="tagsFilter" aria-label="Filter po tagovima">
            <!-- Tags will be injected here -->
        </div>
        
        <nav class="nav" id="docNav">
            <div class="nav-section-title">⏳ Nedavno</div>
            <div id="recentDocs"></div>
            
            <div class="nav-section-title">⭐ Omiljeni</div>
            <div id="favoriteDocs"></div>
            
            <div id="categoryList">
                <?php foreach ($grouped as $cat => $items): ?>
                    <div class="nav-cat-wrapper" data-category="<?= htmlspecialchars($cat) ?>">
                        <div class="nav-cat" onclick="toggleCategory('<?= htmlspecialchars($cat) ?>')" role="button" tabindex="0" aria-expanded="true" aria-label="Proširi kategoriju <?= htmlspecialchars($cat) ?>">
                            <?= htmlspecialchars($cat) ?>
                            <span class="toggle-icon">▼</span>
                        </div>
                        <div class="nav-items">
                            <?php foreach ($items as $item): ?>
                                <a onclick="showDoc('<?= $item['id'] ?>')" 
                                   class="nav-link" 
                                   id="link-<?= $item['id'] ?>" 
                                   data-title="<?= htmlspecialchars(strtolower($item['title'] . ' ' . $item['category'] . ' ' . implode(' ', $item['tags'] ?? []))) ?>"
                                   data-category="<?= htmlspecialchars($cat) ?>"
                                   role="button" tabindex="0"
                                   aria-label="Otvori dokument <?= htmlspecialchars($item['title']) ?>">
                                    <span>
                                        <?= htmlspecialchars($item['title']) ?>
                                        <span class="doc-date"><?= htmlspecialchars($item['updated_at']) ?></span>
                                    </span>
                                    <span class="fav-star" onclick="toggleFavorite(event, '<?= $item['id'] ?>')" title="Dodaj u omiljene" role="button" tabindex="0">☆</span>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </nav>
        
        <!-- Export/Import Section -->
        <div class="export-import-section">
            <a href="?action=export" class="btn" aria-label="Izvezi sve dokumente">📤 Export</a>
            <form method="POST" enctype="multipart/form-data" style="display:inline;" onsubmit="return confirm('Uvesti dokumente?')">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" value="import">
                <input type="file" name="import_file" accept=".json" style="display:none;" id="importFile" onchange="this.form.submit()">
                <button type="button" class="btn" onclick="document.getElementById('importFile').click()" aria-label="Uvezi dokumente">📥 Import</button>
            </form>
        </div>
    </aside>

    <main class="view">
        <header class="top" role="banner">
            <button class="hamb" onclick="toggleMeni()" aria-label="Otvori meni" aria-expanded="false">☰</button>
            <div style="display: flex; align-items: center; gap: 0.5rem;">
                <button class="theme-toggle" id="themeToggle" aria-label="Promeni temu" title="Dark/Light mode">🌙</button>
                <button class="theme-toggle" id="fontToggle" aria-label="Povećaj font" title="Veličina fonta">A+</button>
                <button class="btn" onclick="showShortcutsModal()" aria-label="Prečice na tastaturi">⌨️</button>
                <button class="btn btn-p" onclick="openEdit()">
                    <span>+ Nova Stranica</span>
                </button>
            </div>
        </header>

        <section class="main" id="main-content" aria-live="polite">
            <article class="card" id="displayArea">
                <h1>Dokumentacija</h1>
                <p style="font-size: 1.25rem; color: var(--txt-m);">Izaberite fajl ili otvorite Markdown vodič da vidite sve mogućnosti.</p>
                
                <div class="info-grid">
                    <section class="info-card">
                        <h3>⚡ Performanse</h3>
                        <p>JSON baza sa LOCK_EX zaštitom</p>
                    </section>
                    <section class="info-card">
                        <h3>📱 Responzivno</h3>
                        <p>Podrška za sve uređaje</p>
                    </section>
                    <section class="info-card">
                        <h3>🌙 Dark Mode</h3>
                        <p>Automatsko čuvanje teme</p>
                    </section>
                    <section class="info-card">
                        <h3>♿ Pristupačno</h3>
                        <p>WCAG AA standardi</p>
                    </section>
                </div>
            </article>
        </section>
    </main>

    <!-- TOC Panel -->
    <aside class="toc-panel" id="tocPanel" aria-label="Sadržaj dokumenta">
        <h4>📑 Sadržaj</h4>
        <ul class="toc-list" id="tocList"></ul>
    </aside>

    <!-- Back to Top -->
    <button class="back-to-top" id="backToTop" onclick="scrollToTop()" aria-label="Nazad na vrh">↑</button>

    <!-- Editor Modal -->
    <div class="modal-w" id="editorModal" role="dialog" aria-modal="true" aria-labelledby="modalTitle" aria-hidden="true">
        <div class="modal-b">
            <h2 id="modalTitle" style="margin-bottom: 1.5rem;">Uredi</h2>
            <!-- FORMA ZA ČUVANJE - ISKLJUČIVO ZA ČUVANJE -->
            <form method="POST" id="editorForm" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="doc_id" id="f-id">
                
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;">
                    <input type="text" name="category" id="f-cat" class="f-control" placeholder="Kategorija" required>
                    <input type="text" name="title" id="f-title" class="f-control" placeholder="Naslov" required>
                </div>
                <input type="text" name="description" id="f-desc" class="f-control" placeholder="Kratak opis...">
                <input type="text" name="tags" id="f-tags" class="f-control" placeholder="Tagovi (zarezom razdvojeni): php, tutorial, api">
                
                <!-- Drag Drop Zone -->
                <div class="drop-zone" id="dropZone" onclick="document.getElementById('imageUpload').click()">
                    🖼️ Prevucite sliku ovde ili kliknite za upload
                    <input type="file" id="imageUpload" accept="image/*" style="display: none;" onchange="handleImageUpload(event)">
                </div>
                
                <!-- Editor Tabs -->
                <div class="editor-tabs" role="tablist" aria-label="Režimi editora">
                    <button type="button" class="editor-tab active" id="tab-edit" role="tab" aria-selected="true" aria-controls="editor-write" onclick="switchEditorTab('edit')">✏️ Uređivanje</button>
                    <button type="button" class="editor-tab" id="tab-preview" role="tab" aria-selected="false" aria-controls="editor-preview" onclick="switchEditorTab('preview')">👁️ Pregled</button>
                </div>
                
                <!-- Markdown Toolbar -->
                <div class="markdown-toolbar" id="markdownToolbar">
                    <button type="button" class="toolbar-btn" onclick="insertMarkdown('**', '**')" title="Bold">𝐁</button>
                    <button type="button" class="toolbar-btn" onclick="insertMarkdown('*', '*')" title="Italic">𝐼</button>
                    <button type="button" class="toolbar-btn" onclick="insertMarkdown('`', '`')" title="Kod">Code</button>
                    <button type="button" class="toolbar-btn" onclick="insertMarkdown('[', '](url)')" title="Link">🔗</button>
                    <button type="button" class="toolbar-btn" onclick="insertMarkdown('![alt](', ')')" title="Slika">🖼️</button>
                    <button type="button" class="toolbar-btn" onclick="insertMarkdown('- ', '')" title="Lista">• Lista</button>
                    <button type="button" class="toolbar-btn" onclick="insertMarkdown('1. ', '')" title="Numerisana">1. Lista</button>
                    <button type="button" class="toolbar-btn" onclick="insertMarkdown('> ', '')" title="Citat">❝</button>
                    <button type="button" class="toolbar-btn" onclick="insertMarkdown('```', '\n```')" title="Blok koda">Code Block</button>
                    <button type="button" class="toolbar-btn" onclick="insertMarkdown('| | |\n|-|-|\n| | |', '')" title="Tabela">📊</button>
                    <button type="button" class="toolbar-btn" onclick="insertMarkdown('---', '')" title="Linija">—</button>
                    <button type="button" class="toolbar-btn" onclick="insertMarkdown('[[', ']]')" title="Wiki Link">🔖</button>
                </div>
                
                <!-- Write Tab -->
                <textarea name="content" id="f-content" class="f-control active" placeholder="Markdown kucajte ovde..." role="textbox" aria-label="Sadržaj dokumenta" oninput="updateWordCount()"></textarea>
                
                <!-- Preview Tab -->
                <div id="preview-container" role="region" aria-label="Pregled Markdown sadržaja"></div>
                
                <!-- Word Count -->
                <div class="word-count" id="wordCount">0 reči | 0 karaktera</div>

                <div style="display: flex; justify-content: space-between; align-items: center; margin-top: 1.5rem;">
                    <!-- OVO JE KONTEJNER ZA FORMU ZA BRISANJE - NE SADRŽI FORMU OVDE -->
                    <div id="deleteButtonContainer"></div>
                    <div style="display: flex; gap: 10px;">
                        <button type="button" class="btn" onclick="showVersionHistory()">📜 Verzije</button>
                        <button type="button" class="btn" onclick="closeEdit()">Otkaži</button>
                        <button type="submit" class="btn btn-p">Sačuvaj (Ctrl+S)</button>
                    </div>
                </div>
            </form>
            
            <!-- FORMA ZA BRISANJE - IZDVOJENA IZ FORME ZA ČUVANJE -->
            <form method="POST" id="deleteForm" style="display: none;" onsubmit="return confirm('⚠️ Da li ste sigurni?')">
                <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="doc_id" id="delete_doc_id">
            </form>
        </div>
    </div>

    <!-- Version History Modal -->
    <div class="modal-w" id="versionModal" role="dialog" aria-modal="true" aria-labelledby="versionTitle" aria-hidden="true">
        <div class="modal-b">
            <h2 id="versionTitle" style="margin-bottom: 1.5rem;">📜 Istorija Verzija</h2>
            <div class="version-list" id="versionList"></div>
            <div style="text-align: right; margin-top: 1.5rem;">
                <button class="btn btn-p" onclick="document.getElementById('versionModal').style.display='none'; document.getElementById('versionModal').setAttribute('aria-hidden','true')">Zatvori</button>
            </div>
        </div>
    </div>

    <!-- Shortcuts Modal -->
    <div class="modal-w" id="shortcutsModal" role="dialog" aria-modal="true" aria-labelledby="shortcutsTitle" aria-hidden="true">
        <div class="modal-b">
            <h2 id="shortcutsTitle" style="margin-bottom: 1.5rem;">⌨️ Prečice na Tastaturi</h2>
            <div class="shortcuts-grid">
                <div class="shortcut-item">
                    <span>Pretraga</span>
                    <span class="shortcut-key">Ctrl+K</span>
                </div>
                <div class="shortcut-item">
                    <span>Sačuvaj</span>
                    <span class="shortcut-key">Ctrl+S</span>
                </div>
                <div class="shortcut-item">
                    <span>Preview</span>
                    <span class="shortcut-key">Ctrl+P</span>
                </div>
                <div class="shortcut-item">
                    <span>Zatvori Modal</span>
                    <span class="shortcut-key">Esc</span>
                </div>
                <div class="shortcut-item">
                    <span>Nova Stranica</span>
                    <span class="shortcut-key">Ctrl+N</span>
                </div>
                <div class="shortcut-item">
                    <span>Pomoć</span>
                    <span class="shortcut-key">Ctrl+H</span>
                </div>
            </div>
            <div style="margin-top: 2rem; text-align: right;">
                <button class="btn btn-p" onclick="document.getElementById('shortcutsModal').style.display='none'; document.getElementById('shortcutsModal').setAttribute('aria-hidden','true')">Zatvori</button>
            </div>
        </div>
    </div>

    <!-- Toast Container -->
    <div id="toast-container" aria-live="polite" aria-atomic="true"></div>

    <script>
        const docs = <?= json_encode($docs) ?>;
        const docsById = <?= json_encode($docs_by_id) ?>;
        const allTags = <?= json_encode(array_keys($allTags)) ?>;
        const grouped = <?= json_encode($grouped) ?>;
        
        // --- FUSE.JS FUZZY SEARCH ---
        const fuseOptions = {
            keys: ['title', 'category', 'description', 'tags'],
            threshold: 0.4,
            includeMatches: true,
            minMatchCharLength: 2
        };
        const fuse = new Fuse(docs, fuseOptions);
        
        let currentDocId = null;
        let currentCategory = null;
        let editingDocId = null;

        // --- DARK MODE ---
        const themeToggle = document.getElementById('themeToggle');
        const htmlEl = document.documentElement;
        
        function initTheme() {
            const saved = localStorage.getItem('kraford-theme');
            const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
            if (saved === 'dark' || (!saved && prefersDark)) {
                htmlEl.setAttribute('data-theme', 'dark');
                themeToggle.textContent = '☀️';
            }
        }
        
        themeToggle.addEventListener('click', () => {
            const isDark = htmlEl.getAttribute('data-theme') === 'dark';
            if (isDark) {
                htmlEl.removeAttribute('data-theme');
                localStorage.setItem('kraford-theme', 'light');
                themeToggle.textContent = '🌙';
            } else {
                htmlEl.setAttribute('data-theme', 'dark');
                localStorage.setItem('kraford-theme', 'dark');
                themeToggle.textContent = '☀️';
            }
        });
        
        initTheme();

        // --- FONT SIZE TOGGLE ---
        const fontToggle = document.getElementById('fontToggle');
        let fontSizeLevel = 0;
        
        function applyFontSize(level) {
            if (level === 0) {
                htmlEl.removeAttribute('data-font-size');
                fontToggle.textContent = 'A+';
            } else if (level === 1) {
                htmlEl.setAttribute('data-font-size', 'large');
                fontToggle.textContent = 'A++';
            } else {
                htmlEl.setAttribute('data-font-size', 'xlarge');
                fontToggle.textContent = 'A';
            }
        }
        
        fontToggle.addEventListener('click', () => {
            fontSizeLevel = (fontSizeLevel + 1) % 3;
            applyFontSize(fontSizeLevel);
            localStorage.setItem('kraford-fontsize', fontSizeLevel);
        });
        
        const savedFontSize = localStorage.getItem('kraford-fontsize');
        if (savedFontSize !== null) {
            fontSizeLevel = parseInt(savedFontSize);
            applyFontSize(fontSizeLevel);
        }

        // --- TOAST NOTIFICATIONS ---
        function showToast(message, type = 'success') {
            const container = document.getElementById('toast-container');
            const toast = document.createElement('div');
            toast.className = `toast ${type}`;
            toast.setAttribute('role', 'alert');
            
            const icons = { success: '✅', error: '❌', info: 'ℹ️' };
            toast.innerHTML = `<span>${icons[type] || 'ℹ️'}</span><span>${message}</span>`;
            
            container.appendChild(toast);
            setTimeout(() => toast.remove(), 3000);
        }
        
        <?php if ($toast): ?>
            showToast(<?= json_encode($toast['message']) ?>, <?= json_encode($toast['type']) ?>);
        <?php endif; ?>

        // --- NAVIGATION ---
        function toggleMeni() {
            const sidebar = document.getElementById('sidebar');
            const overlay = document.getElementById('overlay');
            const hamb = document.querySelector('.hamb');
            
            sidebar.classList.toggle('active');
            overlay.classList.toggle('active');
            
            const isActive = sidebar.classList.contains('active');
            overlay.setAttribute('aria-hidden', !isActive);
            hamb.setAttribute('aria-expanded', isActive);
        }

        // --- SEARCH WITH SUGGESTIONS ---
        function handleSearch() {
            const query = document.getElementById('searchInput').value;
            const suggestionsBox = document.getElementById('searchSuggestions');
            
            if (query.length < 2) {
                suggestionsBox.classList.remove('active');
                filterDocs(query);
                return;
            }
            
            const results = fuse.search(query);
            
            if (results.length > 0) {
                suggestionsBox.innerHTML = results.slice(0, 5).map(r => {
                    const doc = r.item;
                    return `<div class="search-suggestion-item" role="option" onclick="showDoc('${doc.id}'); document.getElementById('searchSuggestions').classList.remove('active'); document.getElementById('searchInput').value='';">
                        <strong>${highlightMatch(doc.title, query)}</strong>
                        <div style="font-size:0.8rem; color:var(--txt-m)">${doc.category}</div>
                    </div>`;
                }).join('');
                suggestionsBox.classList.add('active');
            } else {
                suggestionsBox.classList.remove('active');
            }
            
            filterDocs(query);
        }
        
        function highlightMatch(text, query) {
            const regex = new RegExp(`(${query})`, 'gi');
            return text.replace(regex, '<mark>$1</mark>');
        }
        
        function filterDocs(query = '') {
            const q = query.toLowerCase() || document.getElementById('searchInput').value.toLowerCase();
            document.querySelectorAll('.nav-link[data-title]').forEach(link => {
                const title = link.getAttribute('data-title') || '';
                link.style.display = title.includes(q) ? 'flex' : 'none';
            });
        }

        // --- CATEGORY COLLAPSE ---
        function toggleCategory(cat) {
            const wrapper = document.querySelector(`.nav-cat-wrapper[data-category="${cat}"]`);
            const navCat = wrapper.querySelector('.nav-cat');
            const isCollapsed = wrapper.classList.toggle('collapsed');
            navCat.classList.toggle('collapsed');
            navCat.setAttribute('aria-expanded', !isCollapsed);
            
            const collapsed = JSON.parse(localStorage.getItem('kraford-collapsed-cats') || '[]');
            if (isCollapsed) {
                if (!collapsed.includes(cat)) collapsed.push(cat);
            } else {
                const idx = collapsed.indexOf(cat);
                if (idx > -1) collapsed.splice(idx, 1);
            }
            localStorage.setItem('kraford-collapsed-cats', JSON.stringify(collapsed));
        }
        
        // Restore collapsed state
        const collapsedCats = JSON.parse(localStorage.getItem('kraford-collapsed-cats') || '[]');
        collapsedCats.forEach(cat => {
            const wrapper = document.querySelector(`.nav-cat-wrapper[data-category="${cat}"]`);
            if (wrapper) {
                wrapper.classList.add('collapsed');
                wrapper.querySelector('.nav-cat').classList.add('collapsed');
            }
        });

        // --- TAGS FILTER ---
        function initTagsFilter() {
            const container = document.getElementById('tagsFilter');
            container.innerHTML = allTags.map(tag => 
                `<span class="tag-chip" onclick="filterByTag('${tag}', this)">#${tag}</span>`
            ).join('');
        }
        initTagsFilter();
        
        function filterByTag(tag, element) {
            document.querySelectorAll('.tag-chip').forEach(c => c.classList.remove('active'));
            element.classList.toggle('active');
            
            if (element.classList.contains('active')) {
                document.querySelectorAll('.nav-link').forEach(link => {
                    const title = link.getAttribute('data-title') || '';
                    link.style.display = title.includes(tag) ? 'flex' : 'none';
                });
                showToast(`Filter: #${tag}`, 'info');
            } else {
                filterDocs();
            }
        }

        // --- RECENT DOCS ---
        function updateRecentDocs(docId) {
            let recent = JSON.parse(localStorage.getItem('kraford-recent') || '[]');
            recent = recent.filter(id => id !== docId);
            recent.unshift(docId);
            recent = recent.slice(0, 5);
            localStorage.setItem('kraford-recent', JSON.stringify(recent));
            renderRecentDocs();
        }
        
        function renderRecentDocs() {
            const recent = JSON.parse(localStorage.getItem('kraford-recent') || '[]');
            const container = document.getElementById('recentDocs');
            container.innerHTML = recent.map(id => {
                const doc = docsById[id];
                if (!doc) return '';
                return `<a onclick="showDoc('${id}')" class="nav-link" role="button" tabindex="0">${doc.title}</a>`;
            }).join('');
        }
        renderRecentDocs();

        // --- FAVORITES ---
        function toggleFavorite(event, docId) {
            event.stopPropagation();
            let favorites = JSON.parse(localStorage.getItem('kraford-favorites') || '[]');
            const idx = favorites.indexOf(docId);
            
            if (idx > -1) {
                favorites.splice(idx, 1);
                showToast('Uklonjeno iz omiljenih', 'info');
            } else {
                favorites.push(docId);
                showToast('Dodato u omiljene ⭐', 'success');
            }
            
            localStorage.setItem('kraford-favorites', JSON.stringify(favorites));
            renderFavorites();
            updateFavStars();
        }
        
        function renderFavorites() {
            const favorites = JSON.parse(localStorage.getItem('kraford-favorites') || '[]');
            const container = document.getElementById('favoriteDocs');
            container.innerHTML = favorites.map(id => {
                const doc = docsById[id];
                if (!doc) return '';
                return `<a onclick="showDoc('${id}')" class="nav-link" role="button" tabindex="0">${doc.title}</a>`;
            }).join('');
        }
        renderFavorites();
        
        function updateFavStars() {
            const favorites = JSON.parse(localStorage.getItem('kraford-favorites') || '[]');
            document.querySelectorAll('.fav-star').forEach(star => {
                const link = star.closest('.nav-link');
                const docId = link.id.replace('link-', '');
                star.textContent = favorites.includes(docId) ? '★' : '☆';
                star.classList.toggle('active', favorites.includes(docId));
            });
        }
        updateFavStars();

        // --- BREADCRUMBS ---
        function renderBreadcrumbs(category, title, isHome = false, isCheatSheet = false) {
            const container = document.createElement('nav');
            container.className = 'breadcrumbs';
            container.setAttribute('aria-label', 'Navigaciona putanja');
            
            if (isHome) {
                container.innerHTML = `<span class="current">🏠 Početna</span>`;
            } else if (isCheatSheet) {
                container.innerHTML = `
                    <a href="#" onclick="location.reload(); return false;">Početna</a>
                    <span class="separator">›</span>
                    <span class="current">📖 Markdown Vodič</span>
                `;
            } else {
                container.innerHTML = `
                    <a href="#" onclick="location.reload(); return false;">Početna</a>
                    <span class="separator">›</span>
                    <span>${escapeHtml(category)}</span>
                    <span class="separator">›</span>
                    <span class="current">${escapeHtml(title)}</span>
                `;
            }
            return container;
        }
        
        function escapeHtml(str) {
            const div = document.createElement('div');
            div.textContent = str;
            return div.innerHTML;
        }

        // --- TABLE OF CONTENTS ---
        function generateTOC(content) {
            const tocList = document.getElementById('tocList');
            const tocPanel = document.getElementById('tocPanel');
            const parser = new DOMParser();
            const doc = parser.parseFromString(marked.parse(content), 'text/html');
            const headings = doc.querySelectorAll('h2, h3');
            
            if (headings.length < 2) {
                tocPanel.classList.remove('active');
                return;
            }
            
            tocPanel.classList.add('active');
            tocList.innerHTML = Array.from(headings).map((h, i) => {
                const id = `toc-${i}`;
                h.id = id;
                const level = h.tagName === 'H2' ? '' : 'toc-h3';
                return `<li class="${level}"><a href="#${id}" onclick="scrollToSection('${id}')">${h.textContent}</a></li>`;
            }).join('');
        }
        
        function scrollToSection(id) {
            document.getElementById(id)?.scrollIntoView({ behavior: 'smooth' });
        }

        // --- BACK TO TOP ---
        function scrollToTop() {
            document.querySelector('.main').scrollTo({ top: 0, behavior: 'smooth' });
        }
        
        document.querySelector('.main').addEventListener('scroll', () => {
            const btn = document.getElementById('backToTop');
            if (document.querySelector('.main').scrollTop > 500) {
                btn.classList.add('visible');
            } else {
                btn.classList.remove('visible');
            }
        });

        // --- WIKI LINKS PROCESSING ---
        function processWikiLinks(content) {
            return content.replace(/\[\[(.*?)\]\]/g, (match, title) => {
                const foundDoc = docs.find(d => d.title.toLowerCase() === title.toLowerCase());
                if (foundDoc) {
                    return `<a href="#" onclick="showDoc('${foundDoc.id}'); return false;" class="wiki-link">${title}</a>`;
                } else {
                    return `<span class="wiki-link broken" title="Dokument ne postoji">${title}</span>`;
                }
            });
        }

        // --- SHOW DOCUMENT ---
        function showDoc(id) {
            showSkeleton();
            currentDocId = id;
            const doc = docsById[id];
            if(!doc) return;
            
            updateRecentDocs(id);
            currentCategory = doc.category;
            
            let contentHtml = marked.parse(doc.content || '');
            contentHtml = processWikiLinks(contentHtml);
            contentHtml = contentHtml.replace(/<table/g, '<div class="table-wrap" onscroll="checkTableScroll(this)"><table')
                                    .replace(/<\/table>/g, '</table></div>');
            
            // Add copy buttons to code blocks with syntax highlighting
            contentHtml = contentHtml.replace(/<pre><code/g, '<pre><button class="copy-code-btn" onclick="copyCode(this)">📋 Kopiraj</button><code')
                                    .replace(/<\/code><\/pre>/g, '</code></pre>');
            
            const displayArea = document.getElementById('displayArea');
            displayArea.innerHTML = '';
            displayArea.appendChild(renderBreadcrumbs(doc.category, doc.title));
            
            // Tags display
            const tagsHtml = doc.tags ? `<div style="margin-bottom:1rem; display:flex; gap:0.5rem; flex-wrap:wrap;">${doc.tags.map(t => `<span class="tag-chip">#${t}</span>`).join('')}</div>` : '';
            
            const html = `
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom: 1.5rem; flex-wrap: wrap; gap: 0.5rem;">
                    <span style="font-weight:800; color:var(--p); text-transform:uppercase; font-size:0.75rem; background: var(--p-soft); padding: 0.3rem 0.8rem; border-radius: 20px;">${escapeHtml(doc.category)}</span>
                    <div style="display:flex; gap:0.5rem;">
                        <button class="btn" onclick='openEdit(${JSON.stringify(doc).replace(/'/g, "&apos;")})' aria-label="Izmeni dokument">✏️ Izmeni</button>
                        <button class="btn" onclick="toggleFavoriteDoc('${id}')" aria-label="Omiljeni">${localStorage.getItem('kraford-favorites')?.includes(id) ? '★' : '☆'}</button>
                        ${doc.versions && doc.versions.length > 0 ? `<button class="btn" onclick="showDocVersionHistory('${id}')" aria-label="Istorija verzija">📜 ${doc.versions.length}</button>` : ''}
                    </div>
                </div>
                <h1>${escapeHtml(doc.title)}</h1>
                ${tagsHtml}
                <p style="font-size:1.1rem; color:var(--txt-m); margin-bottom:2rem; padding-bottom: 1rem; border-bottom: 1px solid var(--border);">${escapeHtml(doc.description || '')}</p>
                <article class="md-body" id="docContent">${contentHtml}</article>
                
                <!-- Next/Prev Navigation -->
                <nav class="doc-navigation" aria-label="Navigacija između dokumenata">
                    ${getPrevDocLink(doc)}
                    ${getNextDocLink(doc)}
                </nav>
            `;
            displayArea.insertAdjacentHTML('beforeend', html);
            
            // Apply syntax highlighting
            document.querySelectorAll('pre code').forEach((block) => {
                hljs.highlightElement(block);
            });
            
            // Initialize table scroll indicators
            document.querySelectorAll('.table-wrap').forEach(table => {
                checkTableScroll(table);
            });
            
            // Generate TOC
            generateTOC(doc.content);
            
            if(window.innerWidth < 1024 && document.getElementById('sidebar').classList.contains('active')) {
                toggleMeni();
            }
            document.querySelector('.main').scrollTop = 0;
            history.pushState(null, null, `#doc_${id}`);
        }
        
        function checkTableScroll(table) {
            if (table.scrollWidth > table.clientWidth) {
                if (table.scrollLeft + table.clientWidth >= table.scrollWidth - 5) {
                    table.classList.remove('scrolled-right');
                } else {
                    table.classList.add('scrolled-right');
                }
            }
        }
        
        function toggleFavoriteDoc(id) {
            toggleFavorite({ stopPropagation: () => {} }, id);
            showDoc(id);
        }
        
        function getPrevDocLink(doc) {
            const categoryDocs = grouped[doc.category] || [];
            const idx = categoryDocs.findIndex(d => d.id === doc.id);
            if (idx <= 0) return `<div class="nav-btn disabled"><span class="nav-btn-label">Prethodni</span><span class="nav-btn-title">-</span></div>`;
            const prev = categoryDocs[idx - 1];
            return `<a class="nav-btn" onclick="showDoc('${prev.id}'); return false;"><span class="nav-btn-label">← Prethodni</span><span class="nav-btn-title">${escapeHtml(prev.title)}</span></a>`;
        }
        
        function getNextDocLink(doc) {
            const categoryDocs = grouped[doc.category] || [];
            const idx = categoryDocs.findIndex(d => d.id === doc.id);
            if (idx >= categoryDocs.length - 1) return `<div class="nav-btn disabled"><span class="nav-btn-label">Sledeći</span><span class="nav-btn-title">-</span></div>`;
            const next = categoryDocs[idx + 1];
            return `<a class="nav-btn" onclick="showDoc('${next.id}'); return false;" style="margin-left:auto;"><span class="nav-btn-label">Sledeći →</span><span class="nav-btn-title">${escapeHtml(next.title)}</span></a>`;
        }
        
        function showSkeleton() {
            document.getElementById('displayArea').innerHTML = `
                <div class="skeleton">
                    <div class="skeleton-line w-75"></div>
                    <div class="skeleton-line w-100"></div>
                    <div class="skeleton-line w-60"></div>
                    <div class="skeleton-line w-100"></div>
                    <div class="skeleton-line w-75"></div>
                </div>`;
        }

        // --- CHEAT SHEET ---
        function showCheatSheet() {
            const md = `# Markdown Sve Komande
## 1. Tekstualni Stilovi
**Bold tekst**, *Italic tekst*, ~~Precrtano~~, \`Kod u liniji\`

## 2. Liste
- Element 1
- Element 2

## 3. Tabele
| Ime | Funkcija |
| :--- | :--- |
| Pretraga | Brzo filtriranje |

## 4. Blokovi koda
\`\`\`javascript
console.log("Kraford v10.5");
\`\`\`

## 5. Wiki Linkovi
[[Početna Stranica]] - link ka drugom dokumentu`;
            
            let contentHtml = marked.parse(md);
            contentHtml = processWikiLinks(contentHtml);
            contentHtml = contentHtml.replace(/<table/g, '<div class="table-wrap"><table')
                                    .replace(/<\/table>/g, '</table></div>');
            
            const displayArea = document.getElementById('displayArea');
            displayArea.innerHTML = '';
            displayArea.appendChild(renderBreadcrumbs('', '', false, true));
            displayArea.insertAdjacentHTML('beforeend', `<article class="md-body">${contentHtml}</article>`);
            
            document.getElementById('tocPanel').classList.remove('active');
        }

        // --- EDITOR FUNCTIONS ---
        function switchEditorTab(tab) {
            const editTab = document.getElementById('tab-edit');
            const previewTab = document.getElementById('tab-preview');
            const writeArea = document.getElementById('f-content');
            const previewContainer = document.getElementById('preview-container');
            const toolbar = document.getElementById('markdownToolbar');
            
            if (tab === 'preview') {
                editTab.classList.remove('active');
                editTab.setAttribute('aria-selected', 'false');
                previewTab.classList.add('active');
                previewTab.setAttribute('aria-selected', 'true');
                
                writeArea.classList.remove('active');
                toolbar.style.display = 'none';
                
                let previewHtml = marked.parse(writeArea.value || '_Prazan dokument..._');
                previewHtml = processWikiLinks(previewHtml);
                previewHtml = previewHtml.replace(/<table/g, '<div class="table-wrap"><table')
                                        .replace(/<\/table>/g, '</table></div>');
                previewContainer.innerHTML = previewHtml;
                previewContainer.classList.add('active');
                
            } else {
                previewTab.classList.remove('active');
                previewTab.setAttribute('aria-selected', 'false');
                editTab.classList.add('active');
                editTab.setAttribute('aria-selected', 'true');
                
                previewContainer.classList.remove('active');
                toolbar.style.display = 'flex';
                
                writeArea.classList.add('active');
                writeArea.focus();
            }
        }
        
        function insertMarkdown(before, after) {
            const textarea = document.getElementById('f-content');
            const start = textarea.selectionStart;
            const end = textarea.selectionEnd;
            const text = textarea.value;
            const selected = text.substring(start, end);
            
            textarea.value = text.substring(0, start) + before + selected + after + text.substring(end);
            textarea.focus();
            textarea.selectionStart = start + before.length;
            textarea.selectionEnd = end + before.length;
            updateWordCount();
        }
        
        function updateWordCount() {
            const text = document.getElementById('f-content').value;
            const words = text.trim() ? text.trim().split(/\s+/).length : 0;
            const chars = text.length;
            document.getElementById('wordCount').textContent = `${words} reči | ${chars} karaktera`;
        }
        
        // --- IMAGE UPLOAD ---
        const dropZone = document.getElementById('dropZone');
        const imageUpload = document.getElementById('imageUpload');
        
        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('dragover');
        });
        
        dropZone.addEventListener('dragleave', () => {
            dropZone.classList.remove('dragover');
        });
        
        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('dragover');
            const file = e.dataTransfer.files[0];
            if (file && file.type.startsWith('image/')) {
                processImage(file);
            }
        });
        
        function handleImageUpload(event) {
            const file = event.target.files[0];
            if (file) processImage(file);
        }
        
        function processImage(file) {
            const reader = new FileReader();
            reader.onload = (e) => {
                const markdown = `![${file.name}](${e.target.result})`;
                insertMarkdown(markdown, '');
                showToast('Slika dodata! 🖼️', 'success');
            };
            reader.readAsDataURL(file);
        }
        
        // --- COPY CODE ---
        function copyCode(btn) {
            const code = btn.nextElementSibling.innerText;
            navigator.clipboard.writeText(code);
            btn.textContent = '✅ Kopirano!';
            setTimeout(() => btn.textContent = '📋 Kopiraj', 2000);
            showToast('Kod kopiran! 📋', 'success');
        }
        
        // --- VERSION HISTORY ---
        function showVersionHistory() {
            const docId = document.getElementById('f-id').value;
            if (!docId || !docsById[docId]) {
                showToast('Nema verzija za ovaj dokument', 'info');
                return;
            }
            
            const doc = docsById[docId];
            const versions = doc.versions || [];
            
            if (versions.length === 0) {
                showToast('Nema sačuvanih verzija', 'info');
                return;
            }
            
            const versionList = document.getElementById('versionList');
            versionList.innerHTML = versions.map((v, i) => `
                <div class="version-item">
                    <div>
                        <strong>Verzija ${versions.length - i}</strong>
                        <div style="font-size:0.8rem; color:var(--txt-m)">${v.saved_at}</div>
                    </div>
                    <form method="POST" style="display:inline;" onsubmit="return confirm('Vratiti na ovu verziju?')">
                        <input type="hidden" name="csrf_token" value="<?= $_SESSION['csrf_token'] ?>">
                        <input type="hidden" name="action" value="restore_version">
                        <input type="hidden" name="doc_id" value="${docId}">
                        <input type="hidden" name="version_index" value="${i}">
                        <button type="submit" class="btn" style="padding:6px 12px; font-size:0.8rem;">⏮️ Vrati</button>
                    </form>
                </div>
            `).reverse().join('');
            
            document.getElementById('versionModal').style.display = 'flex';
            document.getElementById('versionModal').setAttribute('aria-hidden', 'false');
        }
        
        function showDocVersionHistory(docId) {
            openEdit(docsById[docId]);
            setTimeout(() => showVersionHistory(), 100);
        }
        
        // --- KEYBOARD SHORTCUTS ---
        function showShortcutsModal() {
            const modal = document.getElementById('shortcutsModal');
            modal.style.display = 'flex';
            modal.setAttribute('aria-hidden', 'false');
        }
        
        document.addEventListener('keydown', (e) => {
            if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
            
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                document.getElementById('searchInput').focus();
            }
            if ((e.ctrlKey || e.metaKey) && e.key === 's') {
                e.preventDefault();
                if (document.getElementById('editorModal').style.display === 'flex') {
                    document.getElementById('editorForm').requestSubmit();
                }
            }
            if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
                e.preventDefault();
                if (document.getElementById('editorModal').style.display === 'flex') {
                    switchEditorTab('preview');
                }
            }
            if ((e.ctrlKey || e.metaKey) && e.key === 'n') {
                e.preventDefault();
                openEdit();
            }
            if ((e.ctrlKey || e.metaKey) && e.key === 'h') {
                e.preventDefault();
                showShortcutsModal();
            }
            if (e.key === 'Escape') {
                closeEdit();
                document.getElementById('shortcutsModal').style.display = 'none';
                document.getElementById('shortcutsModal').setAttribute('aria-hidden', 'true');
                document.getElementById('versionModal').style.display = 'none';
                document.getElementById('versionModal').setAttribute('aria-hidden', 'true');
                document.getElementById('searchSuggestions').classList.remove('active');
            }
        });

        // --- MODAL FUNCTIONS ---
        function openEdit(doc = null) {
            const modal = document.getElementById('editorModal');
            editingDocId = doc ? doc.id : null;
            
            document.getElementById('f-id').value = doc ? doc.id : '';
            document.getElementById('f-cat').value = doc ? doc.category : '';
            document.getElementById('f-title').value = doc ? doc.title : '';
            document.getElementById('f-desc').value = doc ? doc.description : '';
            document.getElementById('f-content').value = doc ? doc.content : '';
            document.getElementById('f-tags').value = doc ? (doc.tags || []).join(', ') : '';
            
            document.getElementById('modalTitle').innerText = doc ? '✏️ Uredi stranicu' : '✨ Nova Stranica';
            
            switchEditorTab('edit');
            updateWordCount();
            
            // Dinamički dodaj dugme za brisanje - ne ugnježđujemo formu
            const deleteBtnContainer = document.getElementById('deleteButtonContainer');
            if (doc) {
                deleteBtnContainer.innerHTML = `
                    <button type="button" class="btn" onclick="confirmDelete('${doc.id}')" 
                            style="color:var(--toast-error); border-color:var(--toast-error); background: transparent;">
                        🗑️ Obriši
                    </button>
                `;
            } else {
                deleteBtnContainer.innerHTML = '';
            }
            
            modal.style.display = 'flex';
            modal.setAttribute('aria-hidden', 'false');
            setTimeout(() => document.getElementById('f-title')?.focus(), 100);
            document.addEventListener('keydown', handleEscapeClose);
        }

        function confirmDelete(docId) {
            if (confirm('⚠️ Da li ste sigurni da želite da obrišete ovaj dokument?')) {
                document.getElementById('delete_doc_id').value = docId;
                document.getElementById('deleteForm').submit();
            }
        }

        function handleEscapeClose(e) {
            if (e.key === 'Escape') closeEdit();
        }

        function closeEdit() {
            const modal = document.getElementById('editorModal');
            modal.style.display = 'none';
            modal.setAttribute('aria-hidden', 'true');
            document.removeEventListener('keydown', handleEscapeClose);
        }

        document.getElementById('editorModal').addEventListener('click', (e) => {
            if (e.target.id === 'editorModal') closeEdit();
        });

        // --- KEYBOARD NAV FOR LINKS ---
        document.querySelectorAll('.nav-link[role="button"], .nav-cat[role="button"], .fav-star[role="button"]').forEach(link => {
            link.addEventListener('keydown', (e) => {
                if (e.key === 'Enter' || e.key === ' ') {
                    e.preventDefault();
                    link.click();
                }
            });
        });

        // --- CHECK HASH ON LOAD ---
        window.addEventListener('load', () => {
            const hash = window.location.hash;
            if (hash.startsWith('#doc_')) {
                const id = hash.replace('#doc_', '');
                if (docsById[id]) showDoc(id);
            } else if (hash === '#cheatsheet') {
                showCheatSheet();
            }
        });
    </script>
</body>
</html>
