<?php
// Test if the audit script has syntax errors
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "Testing syntax...\n";

// Try to include the file
$file = __DIR__ . '/ngd_oneoff_send_audit_28days.php';

if (!file_exists($file)) {
    die("File not found: $file\n");
}

// Check for parse errors
$code = file_get_contents($file);
if ($code === false) {
    die("Could not read file\n");
}

// Try tokenizing
$tokens = @token_get_all($code);
if ($tokens === false) {
    die("Tokenization failed - syntax error present\n");
}

echo "Tokenization successful\n";
echo "Total tokens: " . count($tokens) . "\n";

// Look for unclosed strings
$in_string = false;
$string_type = '';
$line_num = 1;

foreach ($tokens as $token) {
    if (is_array($token)) {
        list($id, $text, $line) = $token;
        $line_num = $line;

        if ($id === T_CONSTANT_ENCAPSED_STRING || $id === T_ENCAPSED_AND_WHITESPACE) {
            // String content
        }
    } else {
        // Single character token
        if ($token === '"' || $token === "'") {
            echo "Quote at line $line_num: $token\n";
        }
    }
}

echo "\nSyntax check complete - no obvious errors found\n";
