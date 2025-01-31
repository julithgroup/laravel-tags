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
    use HasTranslations;
    use HasSlug;
    use HasFactory;

    public array $translatable = ['name', 'slug'];
    public $guarded = [];

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
        
        return match (DB::getDriverName()) {
            'pgsql' => $query->whereRaw(
                'lower(' . $this->getQuery()->getGrammar()->wrap("name->{$locale}") . ')::text like ?',
                ['%' . mb_strtolower($name) . '%']
            ),
            default => $query->whereRaw(
                'lower(json_unquote(json_extract(' . $this->getQuery()->getGrammar()->wrap('name') . ', \'$."' . $locale . '"\''.'))) like ?',
                ['%' . mb_strtolower($name) . '%']
            )
        };
    }

    protected function buildJsonWhereClause(Builder $query, string $column, string $locale, string $value): Builder
    {
        return match (DB::getDriverName()) {
            'pgsql' => $query->where("{$column}->{$locale}", $value),
            default => $query->whereRaw(
                "json_unquote(json_extract({$column}, '$.\"" . $locale . "\"')) = ?",
                [$value]
            )
        };
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

    public static function findFromString(string $name, ?string $type = null, ?string $locale = null)
    {
        $locale = $locale ?? static::getLocale();
        
        return static::query()
            ->where('type', $type)
            ->where(function ($query) use ($name, $locale) {
                $instance = new static;
                $instance->buildJsonWhereClause($query, 'name', $locale, $name)
                    ->orWhere(function ($query) use ($instance, $name, $locale) {
                        $instance->buildJsonWhereClause($query, 'slug', $locale, $name);
                    });
            })
            ->first();
    }

    public static function findFromStringOfAnyType(string $name, ?string $locale = null)
    {
        $locale = $locale ?? static::getLocale();
        $instance = new static;

        return static::query()
            ->where(function ($query) use ($instance, $name, $locale) {
                $instance->buildJsonWhereClause($query, 'name', $locale, $name)
                    ->orWhere(function ($query) use ($instance, $name, $locale) {
                        $instance->buildJsonWhereClause($query, 'slug', $locale, $name);
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
}
