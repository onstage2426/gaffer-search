<?php

declare(strict_types=1);

namespace GafferSearch;

/**
 * What an index depends on, for automatic rebuilds (ChangeTracker): a change to a post of one
 * of these post types marks the index as changed; so does a change to one of these meta keys
 * or taxonomies on such a post. A change to anything else — `_edit_lock`, which WordPress
 * writes just by opening the editor, or a revision — is ignored.
 *
 * `parentPostTypes` are post types that aren't documents themselves but are part of their parent
 * post's document (a product's variations): a change to one updates its `post_parent`.
 *
 * @internal Not part of the public API (the engine contract, docs/contract.md, is): may change in any version.
 */
final readonly class Watch
{
    /**
     * @param list<string> $postTypes
     * @param list<string> $metaKeys
     * @param list<string> $taxonomies
     * @param list<string> $parentPostTypes
     */
    public function __construct(
        public array $postTypes = [],
        public array $metaKeys = [],
        public array $taxonomies = [],
        public array $parentPostTypes = [],
    ) {}

    /** A document's own post type, or one whose changes update its parent's document. */
    public function watchesPostType(string $postType): bool
    {
        return in_array($postType, $this->postTypes, true) || $this->updatesParent($postType);
    }

    public function updatesParent(string $postType): bool
    {
        return in_array($postType, $this->parentPostTypes, true);
    }

    public function watchesMetaKey(string $metaKey): bool
    {
        return in_array($metaKey, $this->metaKeys, true);
    }

    public function watchesTaxonomy(string $taxonomy): bool
    {
        return in_array($taxonomy, $this->taxonomies, true);
    }
}
