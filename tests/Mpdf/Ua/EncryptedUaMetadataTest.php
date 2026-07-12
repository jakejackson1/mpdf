<?php

namespace Mpdf\Ua;

/**
 * PDF/UA-1 encrypted-document metadata integrity (audit E3).
 *
 * When a PDF/UA-1 document is encrypted, its XMP metadata stream must stay
 * readable so PDF/UA processors can recover pdfuaid:part and dc:title without
 * the file key. ISO 32000-1:2008 §14.3.2 recommends leaving the metadata stream
 * unencrypted, and §7.6.5 provides the Identity crypt filter as the only valid
 * per-stream bypass — but that filter exists only under a /V 4 security handler.
 *
 * The legacy defect: mPDF declared /Filter [/Crypt] /DecodeParms Identity on the
 * metadata stream while emitting a /V 1|2 RC4 handler with no crypt filters. A
 * conforming reader has no Identity filter to apply, falls back to RC4, and
 * decrypts the plaintext XMP into garbage — destroying dc:title and pdfuaid:part.
 *
 * The fix switches PDFUA+encrypted documents to a /V 4 handler with
 * /EncryptMetadata false, so the Identity filter is a real exemption and the
 * XMP bytes are genuinely plaintext in the output.
 *
 * Spec references:
 *   - ISO 32000-1:2008 §14.3.2 — metadata stream shall not be encrypted
 *   - ISO 32000-1:2008 §7.6.5 — Identity crypt filter
 *   - ISO 32000-1:2008 §7.6.3.3 Algorithm 2 step (g) — 0xFFFFFFFF key marker
 *   - ISO 14289-1:2014 §6.2 — pdfuaid:part XMP identifier
 *   - ISO 14289-1:2014 §7.1 — non-empty document title (dc:title)
 *
 * @group pdfua
 */
class EncryptedUaMetadataTest extends PdfUaTestCase
{

	/**
	 * With PDFUA + SetProtection(['print']) the XMP metadata stream must be
	 * written as genuine plaintext so pdfuaid:part and dc:title survive.
	 *
	 * The metadata stream carries the Identity crypt filter and the encryption
	 * dictionary is /V 4 with /EncryptMetadata false — the combination that makes
	 * the Identity bypass valid. Because the stream is not RC4-encrypted, the XMP
	 * strings appear verbatim in the raw output and need no decryption to read.
	 */
	public function testEncryptedXmpIsReadablePlaintext()
	{
		// PDFUAauto=true so SetProtection(['print']) auto-adds the mandatory
		// 'extract' accessibility permission (Matterhorn 07-001) instead of throwing.
		$mpdf = $this->makeMpdf(['PDFUAauto' => true, 'title' => 'Encrypted UA Title']);
		$mpdf->SetProtection(['print']);
		$output = $this->getOutput($mpdf, '<h1>Encrypted UA</h1><p>Body text.</p>');

		// The document really is encrypted.
		$this->assertStringContainsString('/Encrypt ', $output, 'Document must be encrypted');

		// The encryption dictionary must be /V 4 with a real Identity-capable
		// crypt-filter handler and /EncryptMetadata false.
		$this->assertStringContainsString('/V 4', $output, 'Encrypted PDF/UA must use a /V 4 handler');
		$this->assertStringContainsString('/R 4', $output);
		$this->assertStringContainsString('/EncryptMetadata false', $output);
		$this->assertStringContainsString('/CFM /V2', $output, 'StdCF must define the RC4 (V2) crypt filter');
		$this->assertStringContainsString('/StmF /StdCF', $output);
		$this->assertStringContainsString('/StrF /StdCF', $output);

		// The metadata stream declares the Identity crypt filter bypass.
		$this->assertStringContainsString('/Filter[/Crypt]', $output, 'Metadata stream must carry the Identity crypt filter');
		$this->assertStringContainsString('/Name/Identity', $output);

		// The XMP identifiers must be intact and readable as plaintext — if the
		// stream were RC4-encrypted these would be binary noise.
		$this->assertStringContainsString('<pdfuaid:part>1</pdfuaid:part>', $output, 'pdfuaid:part must be readable plaintext');
		$this->assertStringContainsString('Encrypted UA Title', $output, 'dc:title must be readable plaintext');
		$this->assertMatchesRegularExpression(
			'#<dc:title>.*Encrypted UA Title.*</dc:title>#s',
			$output,
			'dc:title element must contain the document title verbatim'
		);
	}

	/**
	 * The /V 4 switch is scoped to PDF/UA documents. A non-UA encrypted document
	 * must keep the legacy RC4 /V 1|2 handler and must NOT emit /V 4 or the
	 * unencrypted-metadata crypt filter, so existing behaviour is unchanged.
	 */
	public function testNonUaEncryptedDocumentKeepsLegacyHandler()
	{
		$mpdf = new \Mpdf\Mpdf(['mode' => 'c']);
		$mpdf->compress = false;
		$mpdf->SetProtection(['print']);
		$mpdf->WriteHTML('<p>Not a UA document.</p>');
		$output = $mpdf->Output(null, 'S');

		$this->assertStringContainsString('/Encrypt ', $output);
		$this->assertStringNotContainsString('/V 4', $output, 'Non-UA docs must not upgrade to a /V 4 handler');
		$this->assertStringNotContainsString('/EncryptMetadata', $output);
		$this->assertStringNotContainsString('/Filter[/Crypt]', $output);
	}

	/**
	 * The content of an encrypted PDF/UA document (outside the exempt metadata
	 * stream) must still be RC4-encrypted — proving the /V 4 switch did not
	 * accidentally leave the whole document in plaintext.
	 *
	 * With compression disabled the paragraph text would appear verbatim in a
	 * content stream if it were unencrypted; it must not.
	 */
	public function testContentStreamsRemainEncrypted()
	{
		$mpdf = $this->makeMpdf(['PDFUAauto' => true]);
		$mpdf->SetProtection(['print']);
		$marker = 'UniqueEncryptionProbe1234567';
		$output = $this->getOutput($mpdf, '<h1>Heading</h1><p>' . $marker . '</p>');

		// The visible body text must not be recoverable as plaintext — only the
		// XMP metadata stream is exempt from encryption.
		$this->assertStringNotContainsString($marker, $output, 'Body content must be encrypted, not plaintext');
		// Sanity: the XMP identifier still is plaintext.
		$this->assertStringContainsString('<pdfuaid:part>1</pdfuaid:part>', $output);
	}
}
