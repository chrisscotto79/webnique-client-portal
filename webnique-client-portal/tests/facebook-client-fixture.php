<?php
namespace WNQ\Models;
// Standalone tests only: never loaded by the plugin.
final class Client
{
    public static function getAll(): array { return [['id' => 11, 'name' => 'Alice', 'company' => 'Alpha Tree'], ['id' => 22, 'name' => 'Bob', 'company' => 'Beta Welding']]; }
    public static function getById(int $id): ?array { foreach (self::getAll() as $client) if ($client['id'] === $id) return $client; return null; }
}
