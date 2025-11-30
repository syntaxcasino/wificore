<?php

echo "Checking shell_exec availability\n\n";

// Check if shell_exec is disabled
$disabled = explode(',', ini_get('disable_functions'));
echo "Disabled functions: " . ini_get('disable_functions') . "\n\n";

if (in_array('shell_exec', $disabled)) {
    echo "❌ shell_exec is DISABLED\n";
} else {
    echo "✅ shell_exec is ENABLED\n";
}

echo "\nTesting shell_exec:\n";
$result = shell_exec('whoami');
echo "Result: " . ($result ? $result : "EMPTY") . "\n";

echo "\nTesting exec:\n";
exec('whoami', $output, $return_var);
echo "Output: " . implode("\n", $output) . "\n";
echo "Return: " . $return_var . "\n";

echo "\nTesting supervisorctl:\n";
$result = shell_exec('supervisorctl status 2>&1');
echo "Result: " . ($result ? substr($result, 0, 200) : "EMPTY") . "\n";
