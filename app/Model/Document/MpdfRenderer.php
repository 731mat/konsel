<?php

declare(strict_types=1);

namespace App\Model\Document;

use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * PDF přes mPDF (čisté PHP, bez externích služeb). Písmo DejaVu Sans pokrývá češtinu.
 */
final readonly class MpdfRenderer implements PdfRenderer
{
	public function __construct(
		private string $tempDir,
	) {}

	public function render(string $html, string $title): string
	{
		if (!is_dir($this->tempDir)) {
			@mkdir($this->tempDir, 0o777, true);
		}

		$mpdf = new Mpdf([
			'tempDir' => $this->tempDir,
			'mode' => 'utf-8',
			'format' => 'A4',
			'default_font' => 'dejavusans',
			'margin_left' => 20,
			'margin_right' => 20,
			'margin_top' => 18,
			'margin_bottom' => 20,
			'margin_footer' => 8,
		]);
		$mpdf->SetTitle($title);
		$mpdf->SetCreator('Konšel');
		$mpdf->SetFooter('{PAGENO} / {nbpg}');
		$mpdf->WriteHTML($html);

		$pdf = $mpdf->Output('', Destination::STRING_RETURN);

		return is_string($pdf) ? $pdf : throw new \RuntimeException('mPDF did not return PDF content.');
	}
}
