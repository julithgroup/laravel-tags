<?php

namespace Spatie\Tags;

use ArrayAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as DbCollection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\EloquentSortable\Sortable;
use Spatie\EloquentSortable\SortableTrait;
use Spatie\Translatable\HasTranslations;

class Tag extends Model implements Sortable
{
    use SortableTrait;
    use HasTranslations {
        getAttributeValue as protected getTranslatedAttributeValue;
    }
    use HasSlug;
    use HasFactory;

    public array $translatable = ['name', 'slug'];
    public $guarded = [];
    
    protected $casts = [
        'name' => 'json',
        'slug' => 'json',
    ];

    public static function getLocale(): string
    {
        return app()->getLocale();
    }

    public function scopeWithType(Builder $query, ?string $type = null): Builder
    {
        if (is_null($type)) {
            return $query;
        }

        return $query->where('type', $type)->ordered();
    }

    public function scopeContaining(Builder $query, string $name, $locale = null): Builder
    {
        $locale = $locale ?? static::getLocale();
        
        if (DB::getDriverName() === 'pgsql') {
            return $query->whereRaw(
                "name->'" . $locale . "' ? '" . mb_strtolower($name) . "'",
                [],
                'and'
            );
        }
        
        return $query->whereRaw(
            'lower(json_unquote(json_extract(name, \'$."' . $locale . '"\''.'))) like ?',
            ['%' . mb_strtolower($name) . '%']
        );
    }

    public static function findOrCreate(
        string | array | ArrayAccess $values,
        string | null $type = null,
        string | null $locale = null,
    ): Collection | Tag | static {
        $tags = collect($values)->map(function ($value) use ($type, $locale) {
            if ($value instanceof self) {
                return $value;
            }
            return static::findOrCreateFromString($value, $type, $locale);
        });

        return is_string($values) ? $tags->first() : $tags;
    }

    public static function getWithType(string $type): DbCollection
    {
        return static::withType($type)->get();
    }

    protected static function buildJsonFieldQuery(Builder $query, string $field, string $locale, string $value): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $query->whereRaw(
                "({$field}->?)::jsonb = to_jsonb(?::text)",
                [$locale, $value]
            );
        } else {
            $query->whereRaw(
                "json_unquote(json_extract({$field}, '$.\"" . $locale . "\"')) = ?",
                [$value]
            );
        }
    }

    public static function findFromString(string $name, ?string $type = null, ?string $locale = null)
    {
        $locale = $locale ?? static::getLocale();
        
        return static::query()
            ->when($type !== null, function ($query) use ($type) {
                $query->where('type', $type);
            })
            ->where(function ($query) use ($name, $locale) {
                static::buildJsonFieldQuery($query, 'name', $locale, $name);
                $query->orWhere(function ($subQuery) use ($name, $locale) {
                    static::buildJsonFieldQuery($subQuery, 'slug', $locale, $name);
                });
            })
            ->first();
    }

    public static function findFromStringOfAnyType(string $name, ?string $locale = null)
    {
        $locale = $locale ?? static::getLocale();

        return static::query()
            ->where(function ($query) use ($name, $locale) {
                static::buildJsonFieldQuery($query, 'name', $locale, $name);
                $query->orWhere(function ($subQuery) use ($name, $locale) {
                    static::buildJsonFieldQuery($subQuery, 'slug', $locale, $name);
                });
            })
            ->get();
    }

    public static function findOrCreateFromString(string $name, ?string $type = null, ?string $locale = null)
    {
        $locale = $locale ?? static::getLocale();

        $tag = static::findFromString($name, $type, $locale);

        if (! $tag) {
            $tag = static::create([
                'name' => [$locale => $name],
                'type' => $type,
            ]);
        }

        return $tag;
    }

    public static function getTypes(): Collection
    {
        return static::groupBy('type')->pluck('type');
    }

    public function setAttribute($key, $value)
    {
        if (in_array($key, $this->translatable) && ! is_array($value)) {
            return $this->setTranslation($key, static::getLocale(), $value);
        }

        return parent::setAttribute($key, $value);
    }

    public function getAttributeValue($key)
    {
        if (!in_array($key, $this->translatable)) {
            return parent::getAttributeValue($key);
        }

        return $this->getTranslatedAttributeValue($key);
    }
}
