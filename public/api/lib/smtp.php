<?php
declare(strict_types=1);

final class SmtpException extends RuntimeException {}

/**
 * Client SMTP minimal en PHP pur (socket + SSL implicite + AUTH LOGIN), sans
 * dépendance externe : Composer/PHPMailer n'est pas garanti disponible en
 * hébergement mutualisé OVH. Une seule connexion est réutilisée pour tous les
 * envois d'un lot (voir send-news.php) afin d'éviter une authentification par
 * destinataire.
 */
final class SimpleSmtp
{
	/** @var resource */
	private $socket;

	public function __construct(
		private readonly string $host,
		private readonly int $port,
		private readonly string $username,
		private readonly string $password,
		private readonly int $timeout = 15,
	) {
	}

	public function connect(): void
	{
		$context = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true]]);
		$socket = stream_socket_client(
			"ssl://{$this->host}:{$this->port}",
			$errno,
			$errstr,
			$this->timeout,
			STREAM_CLIENT_CONNECT,
			$context,
		);

		if ($socket === false) {
			throw new SmtpException("Connexion SMTP impossible : {$errstr} ({$errno})");
		}

		$this->socket = $socket;
		stream_set_timeout($this->socket, $this->timeout);

		$this->readResponse(220);
		$this->command('EHLO laboiteabivouac.fr', 250);
		$this->command('AUTH LOGIN', 334);
		$this->command(base64_encode($this->username), 334);
		$this->command(base64_encode($this->password), 235);
	}

	/**
	 * @param string|null $texteAlternatif version texte brut du message. Quand
	 *        elle est fournie, $body est envoyé comme HTML dans un
	 *        multipart/alternative ; sinon le message reste en texte brut, comme
	 *        l'attendent la newsletter et le formulaire de contact.
	 */
	public function sendMail(string $toEmail, string $subject, string $body, ?string $unsubscribeUrl = null, ?string $texteAlternatif = null): void
	{
		$this->command('MAIL FROM:<' . SMTP_FROM . '>', 250);
		$this->command('RCPT TO:<' . $toEmail . '>', 250);
		$this->command('DATA', 354);
		$this->command(self::composerMessage($toEmail, $subject, $body, $unsubscribeUrl, $texteAlternatif), 250);
	}

	/**
	 * Assemble le message complet, en-têtes compris, terminé par le point final
	 * de la commande DATA. Séparé de l'envoi pour être vérifiable sans serveur.
	 */
	public static function composerMessage(string $toEmail, string $subject, string $body, ?string $unsubscribeUrl = null, ?string $texteAlternatif = null): string
	{
		$encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
		$encodedFromName = '=?UTF-8?B?' . base64_encode(SMTP_NAME) . '?=';

		$headers = [
			"From: {$encodedFromName} <" . SMTP_FROM . '>',
			"To: <{$toEmail}>",
			"Subject: {$encodedSubject}",
			// Un message sans Date/Message-ID est un signal fort pour les filtres
			// anti-spam (Gmail notamment) : le serveur OVH peut accepter l'envoi
			// (250 OK) alors que le message est ensuite classé en spam ou rejeté
			// silencieusement chez le destinataire final.
			'Date: ' . date('r'),
			'Message-ID: <' . bin2hex(random_bytes(16)) . '@laboiteabivouac.fr>',
			'MIME-Version: 1.0',
		];

		if ($texteAlternatif === null) {
			$headers[] = 'Content-Type: text/plain; charset=UTF-8';
			// Corps encodé en base64 : l'alphabet base64 ne contient jamais de
			// ".", ce qui évite tout souci de "dot-stuffing" SMTP sans avoir à
			// échapper manuellement les lignes du message.
			$headers[] = 'Content-Transfer-Encoding: base64';
			$corps = chunk_split(base64_encode($body));
		} else {
			// Deux versions du même message, la dernière étant celle que le client
			// préfère afficher s'il en est capable (RFC 2046 §5.1.4). Sans cela, un
			// corps HTML annoncé en text/plain s'affiche balises comprises dans les
			// clients qui prennent l'en-tête au mot — constaté le 29/09/2026 : les
			// emails de commande partaient en text/plain avec du HTML dedans, que
			// seule la tolérance de Gmail rendait lisibles.
			$frontiere = 'bab-' . bin2hex(random_bytes(12));
			$headers[] = 'Content-Type: multipart/alternative; boundary="' . $frontiere . '"';
			$corps = "--{$frontiere}\r\n"
				. "Content-Type: text/plain; charset=UTF-8\r\n"
				. "Content-Transfer-Encoding: base64\r\n\r\n"
				. chunk_split(base64_encode($texteAlternatif))
				. "--{$frontiere}\r\n"
				. "Content-Type: text/html; charset=UTF-8\r\n"
				. "Content-Transfer-Encoding: base64\r\n\r\n"
				. chunk_split(base64_encode($body))
				. "--{$frontiere}--\r\n";
		}

		if ($unsubscribeUrl !== null) {
			// Requis par Gmail/Yahoo pour tout envoi assimilable à une newsletter :
			// son absence est elle-même un signal de spam. One-Click (RFC 8058)
			// fonctionne car unsubscribe.php lit email/token depuis la query string,
			// que la requête du client mail arrive en GET ou en POST.
			$headers[] = "List-Unsubscribe: <{$unsubscribeUrl}>";
			$headers[] = 'List-Unsubscribe-Post: List-Unsubscribe=One-Click';
		}

		return implode("\r\n", $headers) . "\r\n\r\n" . $corps . "\r\n.";
	}

	public function quit(): void
	{
		try {
			$this->command('QUIT', 221);
		} finally {
			if (is_resource($this->socket)) {
				fclose($this->socket);
			}
		}
	}

	private function command(string $line, int $expectedCode): string
	{
		fwrite($this->socket, $line . "\r\n");
		return $this->readResponse($expectedCode);
	}

	private function readResponse(int $expectedCode): string
	{
		$response = '';
		while (($line = fgets($this->socket, 515)) !== false) {
			$response .= $line;
			// Une ligne de continuation multi-lignes utilise un tiret après le
			// code ("250-"), la dernière ligne de la réponse utilise une espace ("250 ").
			if (strlen($line) < 4 || $line[3] === ' ') {
				break;
			}
		}

		$code = (int) substr($response, 0, 3);
		if ($code !== $expectedCode) {
			throw new SmtpException("Réponse SMTP inattendue (attendu {$expectedCode}) : " . trim($response));
		}

		return $response;
	}
}
