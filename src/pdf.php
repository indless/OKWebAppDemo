<?php

declare(strict_types=1);

use Dompdf\Dompdf;
use Dompdf\Options;

function pdf_relative_path(int $inspectionId): string
{
    return 'pdfs/' . $inspectionId . '.pdf';
}

function pdf_abs_path(int $inspectionId): string
{
    return storage_path(pdf_relative_path($inspectionId));
}

function generate_inspection_pdf(array $inspection, array $form, array $answers, array $attachments): string
{
    global $twig;

    $photos = [];
    foreach ($attachments as $attachment) {
        $uri = attachment_data_uri($attachment);
        if ($uri) {
            $photos[] = [
                'name' => $attachment['original_name'],
                'src' => $uri,
            ];
        }
    }

    $html = $twig->render('pdf/' . $inspection['form_key'] . '.twig', [
        'inspection' => $inspection,
        'form' => $form,
        'answers' => $answers,
        'fields' => form_fields($form),
        'photos' => $photos,
        'generated_at' => date('n/j/Y g:i A'),
    ]);

    $options = new Options();
    $options->set('isRemoteEnabled', true);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'DejaVu Sans');

    $dompdf = new Dompdf($options);
    $dompdf->loadHtml($html);
    $dompdf->setPaper('letter', 'portrait');
    $dompdf->render();

    ensure_dir(storage_path('pdfs'));
    $abs = pdf_abs_path((int) $inspection['id']);
    file_put_contents($abs, $dompdf->output());

    $relative = pdf_relative_path((int) $inspection['id']);
    set_inspection_pdf_path((int) $inspection['id'], $relative);
    return $relative;
}
