<?php

namespace Spatie\Tags;

use ArrayAccess;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphPivot;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

trait HasTags
{
    protected array $queuedTags = [];

    public static function getTagClassName(): string
    {
        return config('tags.tag_model', Tag::class);
    }

    public static function getTagTableName(): string
    {
        $tagInstance = new (self::getTagClassName());

        return $tagInstance->getTable();
    }

    public static function getTagTablePrimaryKeyName(): string
    {
        return self::getTagTableName() . '.' . self::getTagPrimaryKey();
    }

    public static function getTagPrimaryKey(): string
    {
        $tagInstance = new (self::getTagClassName());

        return $tagInstance->getKeyName();
    }

    public function getTaggableMorphName(): string
    {
        return config('tags.taggable.morph_name', 'taggable');
    }

    public function getTaggableTableName(): string
    {
        return config('tags.taggable.table_name', 'taggables');
    }

    public function getPivotModelClassName(): string
    {
        return config('tags.taggable.class_name', MorphPivot::class);
    }

    public static function bootHasTags()
    {
        static::created(function (Model $taggableModel) {
            if (count($taggableModel->queuedTags) === 0) {
                return;
            }

            $taggableModel->attachTags($taggableModel->queuedTags);

            $taggableModel->queuedTags = [];
        });

        static::deleted(function (Model $deletedModel) {
            $tags = $deletedModel->tags()->get();

            $deletedModel->detachTags($tags);
        });
    }

    public function tags(): MorphToMany
    {
        return $this
            ->morphToMany(self::getTagClassName(), $this->getTaggableMorphName(), $this->getTaggableTableName())
            ->using($this->getPivotModelClassName())
            ->ordered();
    }

    public function tagsTranslated(string | null $locale = null): MorphToMany
    {
        $locale = ! is_null($locale) ? $locale : self::getTagClassName()::getLocale();

        return $this
            ->morphToMany(self::getTagClassName(), $this->getTaggableMorphName(), $this->getTaggableTableName())
            ->using($this->getPivotModelClassName())
            ->select('*')
            ->selectRaw($this->getQuery()->getGrammar()->wrap("name->{$locale} as name_translated"))
            ->selectRaw($this->getQuery()->getGrammar()->wrap("slug->{$locale} as slug_translated"))
            ->ordered();
    }

    public function setTagsAttribute(string | array | ArrayAccess | Tag $tags)
    {
        if (! $this->exists) {
            $this->queuedTags = $tags;

            return;
        }

        $this->syncTags($tags);
    }

    public function scopeWithAllTags(
        Builder $query,
        string | array | ArrayAccess | Tag $tags,
        ?string $type = null,
    ): Builder {
        $tags = static::convertToTags($tags, $type);

        collect($tags)->each(function ($tag) use ($query) {
            $query->whereHas('tags', function (Builder $query) use ($tag) {
                $query->where(self::getTagTablePrimaryKeyName(), $tag->id ?? 0);
            });
        });

        return $query->orderBy('name', 'asc');
    }

    public function scopeWithAnyTags(
        Builder $query,
        string | array | ArrayAccess | Tag $tags,
        ?string $type = null,
    ): Builder {
        $tags = static::convertToTags($tags, $type);

        return $query
            ->whereHas('tags', function (Builder $query) use ($tags) {
                $tagIds = collect($tags)->pluck('id');
                $query->whereIn(self::getTagTablePrimaryKeyName(), $tagIds);
            })
            ->orderBy('id');
    }

    public function scopeWithoutTags(
        Builder $query,
        string | array | ArrayAccess | Tag $tags,
        ?string $type = null
    ): Builder {
        $tags = static::convertToTags($tags, $type);

        return $query
            ->whereDoesntHave('tags', function (Builder $query) use ($tags) {
                $tagIds = collect($tags)->pluck('id');
                $query->whereIn(self::getTagTablePrimaryKeyName(), $tagIds);
            })
            ->orderBy('name');
    }

    public function scopeWithAllTagsOfAnyType(Builder $query, $tags): Builder
    {
        $tags = static::convertToTagsOfAnyType($tags);

        collect($tags)
            ->each(function ($tag) use ($query) {
                $query->whereHas(
                    'tags',
                    fn (Builder $query) => $query->where(self::getTagTablePrimaryKeyName(), $tag ? $tag->id : 0)
                );
            });

        return $query;
    }

    public function scopeWithAnyTagsOfAnyType(Builder $query, $tags): Builder
    {
        $tags = static::convertToTagsOfAnyType($tags);

        $tagIds = collect($tags)->pluck('id');

        return $query
            ->whereHas('tags', function (Builder $query) use ($tagIds) {
                $query->whereIn(self::getTagTablePrimaryKeyName(), $tagIds);
            })
            ->orderBy('id');
    }

    public function tagsWithType(?string $type = null): Collection
    {
        return $this->tags->filter(fn (Tag $tag) => $tag->type === $type);
    }

    public function attachTags(array | ArrayAccess | Tag $tags, ?string $type = null): static
    {
        $tags = static::findOrCreateTags($tags, $type);

        $this->tags()->syncWithoutDetaching($tags->pluck('id')->toArray());

        return $this;
    }

    public function attachTag(string | Tag $tag, string | null $type = null)
    {
        return $this->attachTags([$tag], $type);
    }

    public function detachTags(array | ArrayAccess $tags, string | null $type = null): static
    {
        $tags = static::convertToTags($tags, $type);

        collect($tags)
            ->filter()
            ->each(fn (Tag $tag) => $this->tags()->detach($tag));

        return $this;
    }

    public function detachTag(string $name, ?string $type = null, ?string $locale = null): static
    {
        $locale = $locale ?? Tag::getLocale();

        // Find the specific tag using the same logic as convertToTags
        $className = static::getTagClassName();
        $tag = $className::query()
            ->where(function ($query) use ($name, $locale) {
                if (DB::getDriverName() === 'pgsql') {
                    $query->whereRaw(
                        "name->>" . "'$locale' = ?",
                        [$name]
                    );
                } else {
                    $query->whereRaw(
                        "json_unquote(json_extract(name, '$.\"" . $locale . "\"')) = ?",
                        [$name]
                    );
                }
            })
            ->where(function ($query) use ($type) {
                if ($type === null) {
                    $query->whereNull('type');
                } else {
                    $query->where('type', $type);
                }
            })
            ->first();

        if ($tag) {
            $this->tags()->detach($tag->id);
            $this->unsetRelation('tags');
            $this->load('tags');
        }

        return $this;
    }

    public function syncTags(string | array | ArrayAccess $tags): static
    {
        if (is_string($tags)) {
            $tags = Arr::wrap($tags);
        }

        $className = static::getTagClassName();

        $tags = collect($className::findOrCreate($tags));

        $this->tags()->sync($tags->pluck('id')->toArray());

        return $this;
    }

    public function syncTagsWithType(array | ArrayAccess $tags, string | null $type = null): static
    {
        $className = static::getTagClassName();

        $tags = collect($className::findOrCreate($tags, $type));

        $this->syncTagIds($tags->pluck('id')->toArray(), $type);

        return $this;
    }

    protected static function convertToTags($values, $type = null, $locale = null)
    {
        if ($values instanceof Tag) {
            $values = [$values];
        }

        return collect($values)->map(function ($value) use ($type, $locale) {
            if ($value instanceof Tag) {
                if (isset($type) && $value->type != $type) {
                    throw new InvalidArgumentException("Type was set to {$type} but tag is of type {$value->type}");
                }

                return $value;
            }

            $className = static::getTagClassName();
            $locale = $locale ?? $className::getLocale();

            // Only find existing tags, don't create new ones
            return $className::query()
                ->where(function ($query) use ($value, $locale) {
                    if (DB::getDriverName() === 'pgsql') {
                        $query->whereRaw(
                            "name->>" . "'$locale' = ?",
                            [$value]
                        );
                    } else {
                        $query->whereRaw(
                            "json_unquote(json_extract(name, '$.\"" . $locale . "\"')) = ?",
                            [$value]
                        );
                    }
                })
                ->where(function ($query) use ($type) {
                    if ($type === null) {
                        $query->whereNull('type');
                    } else {
                        $query->where('type', $type);
                    }
                })
                ->first();
        })->filter();
    }

    protected static function findOrCreateTags($values, $type = null, $locale = null)
    {
        if ($values instanceof Tag) {
            $values = [$values];
        }

        return collect($values)->map(function ($value) use ($type, $locale) {
            if ($value instanceof Tag) {
                if (isset($type) && $value->type != $type) {
                    throw new InvalidArgumentException("Type was set to {$type} but tag is of type {$value->type}");
                }

                return $value;
            }

            $className = static::getTagClassName();
            $locale = $locale ?? $className::getLocale();

            // First try to find an existing tag
            $tag = $className::query()
                ->where(function ($query) use ($value, $locale) {
                    if (DB::getDriverName() === 'pgsql') {
                        $query->whereRaw(
                            "name->>" . "'$locale' = ?",
                            [$value]
                        );
                    } else {
                        $query->whereRaw(
                            "json_unquote(json_extract(name, '$.\"" . $locale . "\"')) = ?",
                            [$value]
                        );
                    }
                })
                ->where(function ($query) use ($type) {
                    if ($type === null) {
                        $query->whereNull('type');
                    } else {
                        $query->where('type', $type);
                    }
                })
                ->first();

            // If no tag exists with this name and type, create a new one
            if (! $tag) {
                $tag = $className::create([
                    'name' => [$locale => $value],
                    'type' => $type,
                ]);
            }

            return $tag;
        });
    }

    protected static function convertToTagsOfAnyType($values, $locale = null)
    {
        return collect($values)->map(function ($value) use ($locale) {
            if ($value instanceof Tag) {
                return $value;
            }

            $className = static::getTagClassName();

            // Try to find the tag with any type first
            $tag = $className::query()
                ->where(function ($query) use ($value, $locale, $className) {
                    $locale = $locale ?? $className::getLocale();

                    if (DB::getDriverName() === 'pgsql') {
                        $query->whereRaw(
                            "name->>" . "'$locale' = ?",
                            [$value]
                        );
                    } else {
                        $query->whereRaw(
                            "json_unquote(json_extract(name, '$.\"" . $locale . "\"')) = ?",
                            [$value]
                        );
                    }
                })
                ->get();

            return $tag;
        })->flatten()->filter();
    }

    protected function syncTagIds($ids, string | null $type = null, $detaching = true): void
    {
        $isUpdated = false;

        $tagModel = $this->tags()->getRelated();

        // Get a list of tag_ids for all current tags
        $current = $this->tags()
            ->newPivotStatement()
            ->where($this->getTaggableMorphName() . '_id', $this->getKey())
            ->where($this->getTaggableMorphName() . '_type', $this->getMorphClass())
            ->join(
                $tagModel->getTable(),
                $this->getTaggableTableName() . '.tag_id',
                '=',
                $tagModel->getTable() . '.' . $tagModel->getKeyName()
            )
            ->where($tagModel->getTable() . '.type', $type)
            ->pluck('tag_id')
            ->all();

        // Compare to the list of ids given to find the tags to remove
        $detach = array_diff($current, $ids);
        if ($detaching && count($detach) > 0) {
            $this->tags()->detach($detach);
            $isUpdated = true;
        }

        // Attach any new ids
        $attach = array_unique(array_diff($ids, $current));
        if (count($attach) > 0) {
            $this->tags()->attach($attach, []);

            $isUpdated = true;
        }

        // Once we have finished attaching or detaching the records, we will see if we
        // have done any attaching or detaching, and if we have we will touch these
        // relationships if they are configured to touch on any database updates.
        if ($isUpdated) {
            $this->tags()->touchIfTouching();
        }
    }

    public function hasTag($tag, ?string $type = null): bool
    {
        return $this->tags
            ->when($type !== null, fn ($query) => $query->where('type', $type))
            ->contains(function ($modelTag) use ($tag) {
                return $modelTag->name === $tag || $modelTag->id === $tag;
            });
    }
}
