<?php

namespace App\Services;

/**
 * Versi aplikasi yang tampil di halaman. Nomornya dari berkas VERSION; commit yang sedang tayang dibaca langsung
 * dari folder .git karena hosting bersama biasanya mematikan exec().
 */
class AppVersion
{
    public const FALLBACK = '1.0.0';

    public function number(): string
    {
        $contents = @file_get_contents(base_path('VERSION'));

        return $contents === false ? self::FALLBACK : (trim($contents) ?: self::FALLBACK);
    }

    /**
     * Tujuh karakter pertama commit yang sedang tayang, atau null bila kode bukan hasil git clone.
     */
    public function commit(): ?string
    {
        $git = base_path('.git');
        $head = @file_get_contents($git.'/HEAD');
        if ($head === false) {
            return null;
        }
        $head = trim($head);
        if (! str_starts_with($head, 'ref: ')) {
            return $this->short($head);
        }
        $ref = substr($head, 5);
        $hash = @file_get_contents($git.'/'.$ref);
        if ($hash !== false) {
            return $this->short(trim($hash));
        }
        $packed = @file_get_contents($git.'/packed-refs');
        if ($packed !== false && preg_match('/^([0-9a-f]{40}) '.preg_quote($ref, '/').'$/m', $packed, $match)) {
            return $this->short($match[1]);
        }

        return null;
    }

    /**
     * Sebutan untuk layar, misalnya "v1.0.0 · 5438748".
     */
    public function label(): string
    {
        $commit = $this->commit();

        return 'v'.$this->number().($commit ? ' · '.$commit : '');
    }

    private function short(string $hash): ?string
    {
        return preg_match('/^[0-9a-f]{40}$/', $hash) ? substr($hash, 0, 7) : null;
    }
}
