<?php
declare(strict_types=1);

if (!function_exists('fix_mojibake')) {
  /**
   * Intenta corregir texto mal codificado (Ã¡, Ã±, �, etc.) a UTF-8 limpio.
   */
  function fix_mojibake(?string $text): string {
    if ($text === null) { return ''; }
    $value = (string)$text;
    if ($value === '') { return ''; }
    if (preg_match('/Ã.|Â.|â€|�/u', $value)) {
      $converted = @mb_convert_encoding($value, 'UTF-8', 'ISO-8859-1');
      if ($converted !== false) {
        $value = $converted;
      }
    }
    return $value;
  }
}

