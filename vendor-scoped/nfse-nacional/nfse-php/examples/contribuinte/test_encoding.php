<?php

namespace OpenNfseVendor;

$xml1 = '<?xml version="1.0" encoding="UTF-8"?><root>test</root>';
$xml2 = '<?xml version="1.0" encoding="utf-8"?><root>test</root>';
$xml3 = '<root>test</root>';
echo "Test 1 - UTF-8 (uppercase):\n";
echo '  Hex: ' . \bin2hex(\substr($xml1, 0, 50)) . "\n";
echo '  Encoding: ' . \mb_detect_encoding($xml1, ['UTF-8', 'ISO-8859-1'], \true) . "\n\n";
echo "Test 2 - utf-8 (lowercase):\n";
echo '  Hex: ' . \bin2hex(\substr($xml2, 0, 50)) . "\n";
echo '  Encoding: ' . \mb_detect_encoding($xml2, ['UTF-8', 'ISO-8859-1'], \true) . "\n\n";
echo "Test 3 - No declaration:\n";
echo '  Hex: ' . \bin2hex(\substr($xml3, 0, 50)) . "\n";
echo '  Encoding: ' . \mb_detect_encoding($xml3, ['UTF-8', 'ISO-8859-1'], \true) . "\n\n";
// Check for BOM
$hasBom = \substr($xml1, 0, 3) === "﻿";
echo 'Has BOM: ' . ($hasBom ? 'YES' : 'NO') . "\n";
