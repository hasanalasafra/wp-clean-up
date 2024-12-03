<?php
/**
 * WordPress Core Reinstallation Script
 * This script downloads and reinstalls WordPress core files while preserving:
 * - wp-config.php
 * - wp-content directory (plugins, themes, uploads)
 * - .htaccess
 */

// Set error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Script configurations
define('BACKUP_DIR', './wp-backup-' . date('Y-m-d-His'));
define('WP_LATEST', 'https://wordpress.org/latest.zip');
define('TEMP_ZIP', './wordpress-latest.zip');
define('WORDPRESS_TEMP_DIR', './wordpress');

// Files and directories to preserve
$preserve = array(
    'wp-config.php',
    'wp-content',
    '.htaccess',
    'robots.txt'
);

// Function to check and create directory
function createDirectory($path) {
    if (!file_exists($path)) {
        if (!mkdir($path, 0755, true)) {
            die("Failed to create directory: $path");
        }
    }
    return is_dir($path) && is_writable($path);
}

// Function to safely remove a directory
function removeDirectory($dir) {
    if (!file_exists($dir)) {
        return true;
    }
    
    if (!is_dir($dir)) {
        return unlink($dir);
    }
    
    foreach (scandir($dir) as $item) {
        if ($item == '.' || $item == '..') {
            continue;
        }
        
        if (!removeDirectory($dir . DIRECTORY_SEPARATOR . $item)) {
            return false;
        }
    }
    
    return rmdir($dir);
}

// Function to backup preserved files
function backupPreservedFiles($preserve) {
    foreach ($preserve as $item) {
        if (file_exists($item)) {
            $backup_path = BACKUP_DIR . '/' . $item;
            if (is_dir($item)) {
                recursiveCopy($item, $backup_path);
            } else {
                if (!copy($item, $backup_path)) {
                    die("Failed to backup file: $item");
                }
            }
        }
    }
}

// Function to recursively copy directories
function recursiveCopy($src, $dst) {
    createDirectory($dst);
    $dir = opendir($src);
    
    while (false !== ($file = readdir($dir))) {
        if (($file != '.') && ($file != '..')) {
            $srcFile = $src . '/' . $file;
            $dstFile = $dst . '/' . $file;
            if (is_dir($srcFile)) {
                recursiveCopy($srcFile, $dstFile);
            } else {
                if (!copy($srcFile, $dstFile)) {
                    die("Failed to copy file: $srcFile");
                }
            }
        }
    }
    closedir($dir);
}

// Create backup directory
if (!createDirectory(BACKUP_DIR)) {
    die("Failed to create backup directory");
}

try {
    // Download WordPress
    echo "Downloading WordPress...\n";
    $wpContent = @file_get_contents(WP_LATEST);
    if ($wpContent === false) {
        throw new Exception("Failed to download WordPress from " . WP_LATEST);
    }
    if (!file_put_contents(TEMP_ZIP, $wpContent)) {
        throw new Exception("Failed to save WordPress zip file");
    }

    // Create backup of preserved files
    echo "Creating backup...\n";
    backupPreservedFiles($preserve);

    // Clean up existing wordpress directory if it exists
    if (file_exists(WORDPRESS_TEMP_DIR)) {
        removeDirectory(WORDPRESS_TEMP_DIR);
    }

    // Extract WordPress
    echo "Extracting WordPress...\n";
    $zip = new ZipArchive;
    if ($zip->open(TEMP_ZIP) !== TRUE) {
        throw new Exception("Failed to open WordPress zip file");
    }
    
    if (!$zip->extractTo('./')) {
        $zip->close();
        throw new Exception("Failed to extract WordPress files");
    }
    $zip->close();

    // Verify wordpress directory exists
    if (!file_exists(WORDPRESS_TEMP_DIR) || !is_dir(WORDPRESS_TEMP_DIR)) {
        throw new Exception("WordPress directory not found after extraction");
    }

    echo "Moving WordPress files...\n";
    // Move files from wordpress directory to current directory
    $wordpress_files = array_diff(scandir(WORDPRESS_TEMP_DIR), array('.', '..'));
    foreach ($wordpress_files as $file) {
        $source = WORDPRESS_TEMP_DIR . '/' . $file;
        $destination = './' . $file;
        
        // Skip if file/directory should be preserved
        if (in_array($file, $preserve)) {
            continue;
        }
        
        // Remove existing file/directory
        if (file_exists($destination)) {
            if (is_dir($destination)) {
                removeDirectory($destination);
            } else {
                unlink($destination);
            }
        }
        
        // Move new file/directory
        if (is_dir($source)) {
            recursiveCopy($source, $destination);
        } else {
            if (!copy($source, $destination)) {
                throw new Exception("Failed to copy $source to $destination");
            }
        }
    }

    // Clean up
    echo "Cleaning up...\n";
    if (file_exists(TEMP_ZIP)) {
        unlink(TEMP_ZIP);
    }
    removeDirectory(WORDPRESS_TEMP_DIR);

    echo "WordPress core files have been reinstalled successfully!\n";
    echo "Backup of preserved files is located at: " . BACKUP_DIR . "\n";

} catch (Exception $e) {
    // Clean up temporary files if they exist
    if (file_exists(TEMP_ZIP)) {
        unlink(TEMP_ZIP);
    }
    if (file_exists(WORDPRESS_TEMP_DIR)) {
        removeDirectory(WORDPRESS_TEMP_DIR);
    }
    
    die("Error: " . $e->getMessage());
}
?>