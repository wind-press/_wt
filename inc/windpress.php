<?php

/**
 * WindPress Compatibility File
 * 
 * @link https://wind.press/
 *
 * @package _wt
 */

/**
 * The _wt theme provider callback for the WindPress scanner
 * 
 * @link https://wind.press/docs/integrations/custom-theme#step-2-implement-the-scanners-callback
 * 
 * @return array
 */
function scanner_cb_wt_provider(): array
{
    // The file with this extension will be scanned, you can add more extensions if needed
    $file_extensions = [
        'php',
        'js',
        'html',
    ];

    $contents = [];

    $finder = new \WindPressDeps\Symfony\Component\Finder\Finder();

    // The current active theme
    $wpTheme = wp_get_theme();
    $themeDir = $wpTheme->get_stylesheet_directory();

    // Check if the current theme is a child theme and get the parent theme directory
    $has_parent = $wpTheme->parent() ? true : false;
    $parentThemeDir = $has_parent ? $wpTheme->parent()->get_stylesheet_directory() : null;

    // Scan the theme directory according to the file extensions
    foreach ($file_extensions as $extension) {
        $finder->files()->in($themeDir)->name('*.' . $extension);
        if ($has_parent) {
            $finder->files()->in($parentThemeDir)->name('*.' . $extension);
        }
    }

    // Get the file contents and send to the compiler
    foreach ($finder as $file) {
        $contents[] = [
            'name' => $file->getRelativePathname(),
            'content' => $file->getContents(),
        ];
    }

    return $contents;
}

/**
 * Register the the _wt theme provider for the WindPress scanner
 * 
 * @link https://wind.press/docs/integrations/custom-theme#step-1-register-the-scanner
 * 
 * @param array $providers The collection of providers that will be used to scan the design payload
 * @return array
 */
function register_wt_provider(array $providers): array
{
    $providers[] = [
        'id' => '_wt', // The id of this custom provider. It should be unique across all providers
        'name' => '_wt theme Scanner',
        'description' => 'Scans the current active theme: _wt',
        'callback' => 'scanner_cb_wt_provider', // The function that will be called to get the data. Please see the next step for the implementation
        'enabled' => \WindPress\WindPress\Utils\Config::get(sprintf(
            'integration.%s.enabled',
            '_wt' // The id of this custom provider
        ), true),
    ];

    return $providers;
}
add_filter('f!windpress/core/cache:compile.providers', 'register_wt_provider');

// Register the SFS handler (get and save) for the _wt theme

function _wt_sfs_handler_get(array $sfs_entries): array
{
    $entries = [];

    $data_dir = get_template_directory() . '/tailwind';

    if (! file_exists($data_dir)) {
        return $entries;
    }

    $finder = new \WindPressDeps\Symfony\Component\Finder\Finder();

    $finder
        ->ignoreUnreadableDirs()
        ->in($data_dir)
        ->files()
        ->followLinks()
        ->name(['*.css', '*.js']);

    do_action('a!_wt_sfs_handler_get:get_entries.finder', $finder);

    foreach ($finder as $file) {
        if (! is_readable($file->getPathname())) {
            continue;
        }

        $entries[] = [
            'name' => $file->getFilename(),
            'relative_path' => '@_wt/' . $file->getRelativePathname(),
            'content' => $file->getContents(),
            'handler' => '_wt',
            'signature' => wp_create_nonce(sprintf('%s:%s', '_wt', $file->getRelativePathname())),
        ];
    }

    return array_merge($sfs_entries, $entries);
}
add_filter('f!windpress/core/volume:get_entries.entries', '_wt_sfs_handler_get');

function _wt_sfs_handler_save(array $entry): void
{
    $data_dir = get_template_directory() . '/tailwind/';

    if (! isset($entry['signature'])) {
        return;
    }

    $_relativePath = substr($entry['relative_path'], strlen('@_wt/'));

    // verify the signature
    if (! wp_verify_nonce($entry['signature'], sprintf('%s:%s', '_wt', $_relativePath))) {
        return;
    }

    try {
        // if the content is empty, delete the file.
        if (empty($entry['content'])) {
            \WindPress\WindPress\Utils\Common::delete_file($data_dir . $_relativePath);
        } else {
            \WindPress\WindPress\Utils\Common::save_file($entry['content'], $data_dir . $_relativePath);
        }
    } catch (\Throwable $th) {
        if (WP_DEBUG_LOG) {
            error_log($th->__toString());
        }
    }
}
add_action('a!windpress/core/volume:save_entries.entry._wt', '_wt_sfs_handler_save');
