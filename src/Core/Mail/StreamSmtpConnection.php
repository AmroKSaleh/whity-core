<?php

declare(strict_types=1);

namespace Whity\Core\Mail;

/**
 * Real {@see SmtpConnection} over a PHP stream socket (no curl/SDK dependency).
 * Implicit-TLS ('ssl') dials `ssl://`; plain/STARTTLS dial `tcp://` and upgrade
 * on demand via {@see enableCrypto()}.
 */
final class StreamSmtpConnection implements SmtpConnection
{
    /** @var resource */
    private $stream;

    private function __construct(mixed $stream)
    {
        /** @var resource $stream */
        $this->stream = $stream;
    }

    public static function connect(SmtpConfig $config): self
    {
        $scheme = $config->usesImplicitTls() ? 'ssl' : 'tcp';
        $target = sprintf('%s://%s:%d', $scheme, $config->host, $config->port);

        $context = stream_context_create([
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client(
            $target,
            $errno,
            $errstr,
            (float) $config->timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if ($stream === false) {
            throw new MailException(sprintf('SMTP connect failed to %s:%d (%d)', $config->host, $config->port, $errno));
        }
        stream_set_timeout($stream, $config->timeoutSeconds);

        return new self($stream);
    }

    public function readLine(): string
    {
        $line = fgets($this->stream);
        if ($line === false) {
            $meta = stream_get_meta_data($this->stream);
            $why = $meta['timed_out'] ? 'timeout' : 'connection closed';
            throw new MailException('SMTP read failed: ' . $why);
        }
        return rtrim($line, "\r\n");
    }

    public function write(string $data): void
    {
        $written = @fwrite($this->stream, $data);
        if ($written === false || $written < strlen($data)) {
            throw new MailException('SMTP write failed');
        }
    }

    public function enableCrypto(): void
    {
        $ok = @stream_socket_enable_crypto(
            $this->stream,
            true,
            STREAM_CRYPTO_METHOD_TLS_CLIENT,
        );
        if ($ok !== true) {
            throw new MailException('SMTP STARTTLS negotiation failed');
        }
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            @fclose($this->stream);
        }
    }
}
