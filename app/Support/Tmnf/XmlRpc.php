<?php

namespace App\Support\Tmnf;

use DOMDocument;
use DOMElement;
use DOMNode;

/**
 * The XML-RPC bodies the TMNF dedicated server speaks inside its GBXRemote 2
 * frames (plan "Trackmania und Restposten", P1): a `methodCall` the league
 * sends, a `methodResponse` it answers with, and a `methodCall` it sends by
 * itself as a callback. No PHP extension: PHP 8 ships none for XML-RPC.
 *
 * Types both ways: int/i4, boolean, string (also an untyped value), double,
 * base64 (decoded to a string; sent with {@see base64()}), array, struct.
 * A document type in an answer is refused (no entity is ever expanded), as is
 * anything that is not one of these shapes (GbxProtocolError).
 */
final class XmlRpc
{
    /**
     * A value marked to be sent as `<base64>`, not as a string.
     *
     * @return array{__base64: string}
     */
    public static function base64(string $bytes): array
    {
        return ['__base64' => $bytes];
    }

    /**
     * @param  list<mixed>  $params
     */
    public static function encodeCall(string $method, array $params = []): string
    {
        if (preg_match('/^[A-Za-z0-9_.:]{1,128}$/', $method) !== 1) {
            throw new GbxProtocolError("Not a method name: [{$method}].");
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?><methodCall><methodName>'.$method.'</methodName><params>';

        foreach ($params as $param) {
            $xml .= '<param>'.self::encodeValue($param).'</param>';
        }

        return $xml.'</params></methodCall>';
    }

    /**
     * The value of a `methodResponse`.
     *
     * @throws GbxFault when the server answered with a fault
     */
    public static function decodeResponse(string $xml): mixed
    {
        $root = self::root($xml, 'methodResponse');
        $fault = self::child($root, 'fault');

        if ($fault !== null) {
            $value = self::decodeValue(self::required($fault, 'value'));
            $code = is_array($value) && is_int($value['faultCode'] ?? null) ? $value['faultCode'] : 0;
            $message = is_array($value) && is_string($value['faultString'] ?? null) ? $value['faultString'] : 'unknown fault';

            throw new GbxFault($message, $code);
        }

        $params = self::decodeParams(self::required($root, 'params'));

        return $params[0] ?? null;
    }

    /**
     * The method name and parameters of a `methodCall` (a callback of the server).
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public static function decodeCall(string $xml): array
    {
        $root = self::root($xml, 'methodCall');
        $name = trim(self::required($root, 'methodName')->textContent);

        if ($name === '') {
            throw new GbxProtocolError('A methodCall without a methodName.');
        }

        $params = self::child($root, 'params');

        return [$name, $params === null ? [] : self::decodeParams($params)];
    }

    private static function encodeValue(mixed $value): string
    {
        return '<value>'.match (true) {
            is_bool($value) => '<boolean>'.($value ? '1' : '0').'</boolean>',
            is_int($value) => '<int>'.$value.'</int>',
            is_float($value) => '<double>'.self::double($value).'</double>',
            is_string($value) => '<string>'.htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</string>',
            is_array($value) && array_keys($value) === ['__base64'] && is_string($value['__base64']) => '<base64>'.base64_encode($value['__base64']).'</base64>',
            is_array($value) && array_is_list($value) => '<array><data>'.implode('', array_map(self::encodeValue(...), $value)).'</data></array>',
            is_array($value) => '<struct>'.implode('', array_map(
                fn (int|string $key, mixed $member): string => '<member><name>'.htmlspecialchars((string) $key, ENT_XML1 | ENT_QUOTES, 'UTF-8').'</name>'.self::encodeValue($member).'</member>',
                array_keys($value), $value,
            )).'</struct>',
            default => throw new GbxProtocolError('A value XML-RPC cannot carry: '.get_debug_type($value).'.'),
        }.'</value>';
    }

    private static function double(float $value): string
    {
        if (! is_finite($value)) {
            throw new GbxProtocolError('XML-RPC carries no infinite or NaN double.');
        }

        $text = rtrim(rtrim(sprintf('%.17F', $value), '0'), '.');

        return $text === '' || $text === '-' ? '0' : $text;
    }

    /**
     * @return list<mixed>
     */
    private static function decodeParams(DOMElement $params): array
    {
        $values = [];

        foreach (self::elements($params) as $param) {
            if ($param->tagName !== 'param') {
                throw new GbxProtocolError("Unexpected <{$param->tagName}> in <params>.");
            }

            $values[] = self::decodeValue(self::required($param, 'value'));
        }

        return $values;
    }

    private static function decodeValue(DOMElement $value): mixed
    {
        $typed = self::elements($value);

        // An untyped value is a string.
        if ($typed === []) {
            return $value->textContent;
        }

        if (count($typed) !== 1) {
            throw new GbxProtocolError('A <value> with more than one type.');
        }

        $node = $typed[0];
        $text = $node->textContent;

        return match ($node->tagName) {
            'int', 'i4' => self::integer($text),
            'boolean' => match (trim($text)) {
                '1' => true,
                '0' => false,
                default => throw new GbxProtocolError("Not a boolean: [{$text}]."),
            },
            'string' => $text,
            'double' => is_numeric(trim($text)) ? (float) trim($text) : throw new GbxProtocolError("Not a double: [{$text}]."),
            'base64' => self::bytes($text),
            'array' => array_map(self::decodeValue(...), self::listOf(self::required($node, 'data'), 'value')),
            'struct' => self::struct($node),
            'nil' => null,
            default => throw new GbxProtocolError("Unknown XML-RPC type <{$node->tagName}>."),
        };
    }

    private static function integer(string $text): int
    {
        $text = trim($text);

        if (preg_match('/^[+-]?\d{1,10}$/', $text) !== 1) {
            throw new GbxProtocolError("Not an int: [{$text}].");
        }

        return (int) $text;
    }

    private static function bytes(string $text): string
    {
        $bytes = base64_decode((string) preg_replace('/\s+/', '', $text), true);

        return $bytes === false ? throw new GbxProtocolError('Not base64.') : $bytes;
    }

    /**
     * @return array<string, mixed>
     */
    private static function struct(DOMElement $struct): array
    {
        $members = [];

        foreach (self::listOf($struct, 'member') as $member) {
            $members[trim(self::required($member, 'name')->textContent)] = self::decodeValue(self::required($member, 'value'));
        }

        return $members;
    }

    private static function root(string $xml, string $expected): DOMElement
    {
        if (str_contains($xml, '<!DOCTYPE') || str_contains($xml, '<!ENTITY')) {
            throw new GbxProtocolError('An XML-RPC body with a document type is refused.');
        }

        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);

        try {
            $loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        $root = $document->documentElement;

        if (! $loaded || ! $root instanceof DOMElement || $root->tagName !== $expected) {
            throw new GbxProtocolError("Expected a <{$expected}>.");
        }

        return $root;
    }

    /**
     * @return list<DOMElement>
     */
    private static function elements(DOMNode $node): array
    {
        $elements = [];

        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement) {
                $elements[] = $child;
            }
        }

        return $elements;
    }

    /**
     * @return list<DOMElement>
     */
    private static function listOf(DOMElement $parent, string $tag): array
    {
        $elements = self::elements($parent);

        foreach ($elements as $element) {
            if ($element->tagName !== $tag) {
                throw new GbxProtocolError("Unexpected <{$element->tagName}> where <{$tag}> belongs.");
            }
        }

        return $elements;
    }

    private static function child(DOMElement $parent, string $tag): ?DOMElement
    {
        foreach (self::elements($parent) as $element) {
            if ($element->tagName === $tag) {
                return $element;
            }
        }

        return null;
    }

    private static function required(DOMElement $parent, string $tag): DOMElement
    {
        return self::child($parent, $tag) ?? throw new GbxProtocolError("Missing <{$tag}> in <{$parent->tagName}>.");
    }
}
