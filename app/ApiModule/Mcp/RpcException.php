<?php

declare(strict_types=1);

namespace App\ApiModule\Mcp;

/**
 * Chyba protokolu JSON-RPC (neznámá metoda, neplatné parametry).
 */
final class RpcException extends \RuntimeException
{
	public const int ParseError = -32700;

	public const int InvalidRequest = -32600;

	public const int MethodNotFound = -32601;

	public const int InvalidParams = -32602;

	public const int InternalError = -32603;
}
