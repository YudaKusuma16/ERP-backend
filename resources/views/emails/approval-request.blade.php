<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Permintaan Approval</title>
</head>

<body
    style="margin: 0; padding: 0; background-color: #f4f4f4; font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
        style="background-color: #f4f4f4; padding: 20px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0"
                    style="background-color: #ffffff; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">

                    <!-- Header -->
                    <tr>
                        <td style="background-color: #2563eb; padding: 24px 30px; text-align: center;">
                            <h1 style="margin: 0; color: #ffffff; font-size: 22px; font-weight: 700;">
                                Permintaan Approval
                            </h1>
                        </td>
                    </tr>

                    <!-- Greeting -->
                    <tr>
                        <td style="padding: 30px 30px 10px 30px;">
                            <p style="margin: 0; font-size: 16px; color: #333;">
                                Halo <strong>{{ $data['approver_name'] }}</strong>,
                            </p>
                            <p style="margin: 8px 0 0 0; font-size: 14px; color: #555;">
                                Ada dokumen yang memerlukan persetujuan Anda:
                            </p>
                        </td>
                    </tr>

                    <!-- Document Details -->
                    <tr>
                        <td style="padding: 20px 30px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0"
                                style="background-color: #f8fafc; border-left: 4px solid #2563eb; border-radius: 4px;">
                                <tr>
                                    <td style="padding: 20px;">
                                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                            @foreach([
                                                    [
                                                        'label' => 'Jenis Dokumen',
                                                        'value' => $data['document_type_label'] ?? match ($data['document_type'] ?? '') {
                                                            'mr' => 'Material Request',
                                                            'sr' => 'Service Request',
                                                            'pr' => 'Purchase Requisition',
                                                            'po' => 'Purchase Order',
                                                            default => $data['document_type'] ?? '-',
                                                        }
                                                    ],
                                                    ['label' => 'Nomor Dokumen', 'value' => '#' . ($data['document_number'] ?? '-')],
                                                    ['label' => 'Pengaju', 'value' => $data['requester_name'] ?? '-'],
                                                    ['label' => 'Departemen', 'value' => $data['department'] ?? '-'],
                                                ] as $item)
                                                <tr>
                                                    <td
                                                        style="padding: 8px 0; border-bottom: 1px solid #e2e8f0; width: 40%; vertical-align: top;">
                                                        <span
                                                            style="font-weight: 600; color: #64748b; font-size: 13px;">{{ $item['label'] }}</span>
                                                    </td>
                                                    <td
                                                        style="padding: 8px 0; border-bottom: 1px solid #e2e8f0; vertical-align: top;">
                                                        <span style="font-weight: 500; color: #1e293b; font-size: 14px;">{{ $item['value'] }}</span>
                                                    </td>
                                                </tr>
                                            @endforeach

                                            @if(isset($data['total_amount']) && $data['total_amount'])
                                                                            <tr>
                                                                                <td style="padding: 8px 0; border-bottom: 1px solid #e2e8f0; width: 40%; vertical-align: top;">
                                                                                    <span
                                                                                        style="font-weight: 600; color: #64748b; font-size: 13px;">Total N
                                                   i                                    lai</span>
                                                                                </td>
                                                                                <td style="padding: 8px 0; border-bottom: 1px solid #e2e8f0; vertical-align: top;">
                                                                                    <span
                                                                                        style="font-weight: 700; color: #059669; font-size: 18px;">Rp {{ number_format($data['total_amount'], 0, ',', '.') }}</span>
                                                                                </td>
                                                                            </tr>
                                            @endif

                                            @if(isset($data['current_tier']) && isset($data['total_tiers']))
                                                <tr>
                                                    <td style="padding: 8px 0; width: 40%; vertical-align: top;">
                                                        <span
                                                            style="font-weight: 600; color: #64748b; font-size: 13px;">Tingkat Approval</span>
                                                    </td>
                                                    <td style="padding: 8px 0; vertical-align: top;">
                                                        <span
                                                            style="font-weight: 500; color: #1e293b; font-size: 14px;">Tier {{ $data['current_tier'] }} dari {{ $data['total_tiers'] }}</span>
                                                    </td>
                                                </tr>
                                            @endif

                                            @if(isset($data['notes']) && $data['notes'])
                                                <tr>
                                                    <td style="padding: 8px 0; width: 40%; vertical-align: top;">
                                                        <span style="font-weight: 600; color: #64748b; font-size: 13px;">Catatan</span>
                                                    </td>
                                                    <td style="padding: 8px 0; vertical-align: top;">
                                                        <span style="font-weight: 500; color: #1e293b; font-size: 14px;">{{ $data['notes'] }}</span>
                                                    </td>
                                                </tr>
                                            @endif
                                        </table>
  
                                                                 </td>
   
                                                            </tr>
                            </table>
                        </td>

                                                                                       
                    </tr>

                                                                                       

                                           

                                                                @if(!empty($data['line_items']))

                                                                                                                <!-- Line Items -->
                                                                    <tr>


                                                                                                                    <td style="padding: 0 30px 10px 30px;">
                                                                            <p style="margin: 0 0 10px 0; font-size: 14px; font-weight: 600; color: #1e293b;">Detail Item</p>
                                                                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border: 1px solid #e2e8f0; border-radius: 4px; overflow: hidden;">
                                                                                <thead>
                                                                                    <tr style="background-color: #f1f5f9;">
                                                                                        <th style="padding: 10px 12px; text-align: left; font-size: 12px; font-weight: 600; color: #475569; border-bottom: 1px solid #e2e8f0; width: 5%;">#</th>
                                                                                        <th
                                                                                            style="padding: 10px 12px; text-align: left; font-size: 12px; font-weight: 600; color: #475569
                                                                                            ; border-bottom: 1px solid #e2e8f0;">Nama Item</th>
                                                                                        <th
                                                                                            style="padding: 10px 12px; text-align: center; font-size: 12px; font-weight: 600; color: #475569; border-bottom: 1px solid #e2e8f0; width: 15%;">Qty</th>
                                                                                        <th style="padding: 10px 12px; text-align: center; font-size: 12px; font-weight: 600; color: #475569; border-bottom: 1px solid #e2e8f0; width: 12%;">Satuan</th>
                                                                                        @if(isset($data['document_type']) && in_array($data['document_type'], ['sr', 'pr', 'po']))
                                                                                            <th style="pa
                                                                                                   dding: 10px 12px; text-align: right; font-size: 12px; font-weight: 600; color: #475569; border-bottom: 1px solid #e2e8f0; width: 20%;">Harga</th>
                                                                                        @endif
                                                                                    </tr>
                                                                                </thead>


                                                                                                                           <tbody>


                                                                                                                                @foreach($data['line_items'] as $index => $item)
                                                                                                                                    <tr sty
                                                                                                                                           le="{{ $loop->iteration % 2 === 0 ? 'background-color: #f8fafc;' : '' }}">
                                                                                                                                        <td style="padding: 8px 12px; font-size: 13px; color: #64748b; border-bottom: 1px solid #f1f5f9;">{{ $index + 1 }}</td>
                                                                                                                                        <td style="padding: 8px 12px; font-size: 13px; color: #1e293b; border-bottom: 1px solid #f1f5f9;">
                                                                                                                                            {{ $item['name'] ?? '-' }}
                                                                                                                                            @if(!empty($item['description']))
                                                                                                                                                <br><span style="font-size: 12px; color: #94a3b8;">{{ $item['description'] }}</span>
                                                                                                                                            @endif
                                                                                                                                        </td>
                                                                                                                                        <td style="padding: 8px 12px; font-size: 13px; color: #1e293b; text-align: center; border-bottom: 1px solid #f1f5f9;">{{ $item['qty'] ?? '-' }}</td>
                                                                                                                                        <td style="padding: 8px 12px; font-size: 13px; color: #1e293b; text-align: center; border-bottom: 1px solid #f1f5f9;">{{ $item['unit'] ?? '-' }}</td>
                                                                                                                                        @if(isset($data['document_type']) && in_array($data['document_type'], ['sr', 'pr', 'po']))

                                                                                                                                            <td style="padding: 8px 12px; font-size: 13px; color: #1e293b; text-align: right; border-bottom: 1px solid #f1f5f9;">
                                                                                                                                                {{ isset($item['price']) && $item['price'] ? 'Rp ' . number_format($item['price'], 0, ',', '.') : '-' }}
                                                                                                                                            </td>
                                                                                                                                        @endif
                                                                                                                                    </tr>
                                                                                                                                @endforeach
                                                                                </tbody>
                                                                            </table>
                                                                            @if(isset($data['total_amount']) && $data['total_amount'] && in_array($data['document_type'], ['pr', 'po']))
                                                                                <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                                                                                    <tr>
                                                                                        <td style="padding: 10px 12px; text-align: right; font-size: 14px; font-weight: 700; color: #059669; border-top: 2px solid #e2e8f0;">
                                                                                            Total: Rp {{ number_format($data['total_amount'], 0, ',', '.') }}
                                                                                        </td>
                                                                                    </tr>
                                                                                </table>
                                                                            @endif
                                                                        </td>
                                                                    </tr>
                                                                @endif
 
                    <!-- Action Buttons -->
                    <tr>
                        <td style="padding: 10px 30px 30px 30px; text-align: center;">
                            <p style="margin: 0 0 15px 0; font-size: 14px; color: #555;">
                                Silakan pilih tindakan di bawah ini:
                             </p>
                            <table role="presentation" cellpadding="0" cellspacing="0" style="margin: 0 auto;">
                                <tr>
                                    <td style="padding: 0 8px;">
                                        <a href="{{ $data['approval_url'] }}"
                                           style="display: inline-block; padding: 14px 32px; background-color: #10b981; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 15px;">
                                            Setuju
                                        </a>
                                    </td>
                                    <td style="padding: 0 8px;">
                                        <a href="{{ $data['reject_url'] }}"
                                           style="display: inline-block; padding: 14px 32px; background-color: #ef4444; color: #ffffff; text-decoration: none; border-radius: 6px; font-weight: 600; font-size: 15px;">
                                            Tolak

                                                                       </a>
                                    </td>
                                </tr>

                                       
                                                                   </table>
                        </td>
                    </tr>

                    <!-- Expiration Warning -->
                    <tr>
                        <td style="padding: 0 30px 20px 30px;">
                            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color: #fef3c7; border: 1px solid #f59e0b; border-radius: 4px;">
                                <tr>
       
                             <td style="padding: 15px; font-size: 13px; color: #92400e;">
                                        <strong>Perhatian:</strong> Link ini hanya berlaku sampai <strong>{{ $data['expires_at'] ?? '48 jam dari sekarang' }}</strong>. Setelah itu, silakan login ke sistem ERP untuk melakukan approval.
                                    </td>
                                </tr>
                            </table>
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>