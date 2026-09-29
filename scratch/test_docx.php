<?php

require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\IOFactory;
use Smalot\PdfParser\Parser as PdfParser;

echo "Testing attachments logic...\n";

$parser = new PdfParser();
echo "PdfParser instantiated successfully.\n";
