<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/credentials.php';
try { echo installationKey() . PHP_EOL; }
catch (RuntimeException $exception) { fwrite(STDERR, $exception->getMessage() . PHP_EOL); exit(1); }
