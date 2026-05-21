<?php

namespace App\Services;

use App\Models\DocumentSequence;

class DocumentNumberingService
{
    public function generate(string $documentType, ?string $prefix = null): string
    {
        $prefixMap = [
            'mr' => 'MR',
            'sr' => 'SR',
            'pr' => 'PR',
            'po' => 'PO',
            'pre_rd' => 'PRE-RD',
            'rd' => 'RD',
            'orf' => 'ORF',
            'so' => 'SO',
            'wo' => 'WO',
            'al' => 'AL',
            'di' => 'DI',
            'dn' => 'DN',
            'rrv' => 'RRV',
        ];

        $resolvedPrefix = $prefix ?? ($prefixMap[$documentType] ?? strtoupper($documentType));

        return DocumentSequence::generateNumber($documentType, $resolvedPrefix);
    }
}