<?php

namespace App\Support;

use App\Models\Work;

class ObraContext
{
    private const SESSION_KEY = 'obra_context_id';

    public static function set(Work|string $obra): void
    {
        $id = $obra instanceof Work ? $obra->id : $obra;
        session([self::SESSION_KEY => $id]);
    }

    public static function currentId(): ?string
    {
        return session(self::SESSION_KEY);
    }

    public static function current(): ?Work
    {
        $id = static::currentId();

        return $id ? Work::find($id) : null;
    }

    public static function clear(): void
    {
        session()->forget(self::SESSION_KEY);
    }
}
