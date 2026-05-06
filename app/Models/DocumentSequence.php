<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class DocumentSequence extends Model
{
    protected $fillable = [
        'document_type',
        'prefix',
        'year',
        'month',
        'current_sequence',
        'reset_period',
    ];

    public static function generateNumber(string $documentType, string $prefix = null): string
    {
        $now = now();
        $year = $now->year;
        $month = $now->month;

        if ($prefix === null) {
            $prefix = strtoupper($documentType);
        }

        return DB::transaction(function () use ($documentType, $prefix, $year, $month) {
            $sequence = static::lockForUpdate()
                ->where('document_type', $documentType)
                ->where('year', $year)
                ->where('month', $month)
                ->first();

            if (!$sequence) {
                $sequence = static::create([
                    'document_type' => $documentType,
                    'prefix' => $prefix,
                    'year' => $year,
                    'month' => $month,
                    'current_sequence' => 0,
                    'reset_period' => 'monthly',
                ]);
            }

            $sequence->increment('current_sequence');
            $sequence->refresh();

            $sequenceNumber = str_pad($sequence->current_sequence, 4, '0', STR_PAD_LEFT);

            return sprintf('%s-%s-%s-%s', $prefix, $year, str_pad($month, 2, '0', '0'), $sequenceNumber);
        });
    }
}