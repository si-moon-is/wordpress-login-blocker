<?php
/**
 * Generator pliku POT dla Login Blocker
 */

$plugin_path = dirname(__DIR__);
$pot_file = $plugin_path . '/languages/login-blocker.pot';

// Funkcja do skanowania plików PHP w poszukiwaniu stringów do tłumaczenia
function extract_translatable_strings($directory) {
    $strings = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    
    foreach ($files as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $content = file_get_contents($file->getRealPath());
            preg_match_all('/__\([\'"]([^\'"]+)[\'"][^)]*\)/i', $content, $matches);
            if (!empty($matches[1])) {
                $strings = array_merge($strings, $matches[1]);
            }
            preg_match_all('/_e\([\'"]([^\'"]+)[\'"][^)]*\)/i', $content, $matches);
            if (!empty($matches[1])) {
                $strings = array_merge($strings, $matches[1]);
            }
        }
    }
    
    return array_unique($strings);
}

$strings = extract_translatable_strings($plugin_path . '/includes');
$admin_strings = extract_translatable_strings($plugin_path);

$all_strings = array_unique(array_merge($strings, $admin_strings));

// Generuj plik POT
$pot_content = <<<POT
msgid ""
msgstr ""
"Project-Id-Version: WordPress Login Blocker\\n"
"POT-Creation-Date: 2024-01-01 12:00+0000\\n"
"PO-Revision-Date: 2024-01-01 12:00+0000\\n"
"Last-Translator: Your Name <email@example.com>\\n"
"Language-Team: Your Team <team@example.com>\\n"
"Language: \\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"Plural-Forms: nplurals=2; plural=(n != 1);\\n"

POT;

foreach ($all_strings as $string) {
    $pot_content .= "\nmsgid \"$string\"\n";
    $pot_content .= "msgstr \"\"\n";
}

file_put_contents($pot_file, $pot_content);
echo "POT file generated: $pot_file\n";
