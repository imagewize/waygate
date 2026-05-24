# Waygate Roadmap & Improvement Ideas

> **Document Version**: 1.1.0  
> **Last Updated**: 2026-05-24  
> **Status**: Draft

This document outlines potential improvements and feature additions for Waygate, based on WordPress 7.0 AI Client and Abilities API capabilities.

---

## Current State Analysis

### What Waygate Does Well

| Feature | Implementation | Status |
|---------|---------------|--------|
| Pattern catalog | Lists Elayne patterns with metadata | ✅ Complete |
| AI page generation | Natural language → pattern selection → draft page | ✅ Complete |
| Abilities API | `elayne/list-patterns`, `elayne/create-page` | ✅ Complete |
| Multi-provider | Mistral, Claude, OpenAI, Gemini via WP AI Client | ✅ Complete |
| Admin UI | Tools → Waygate page | ✅ Complete |

### Current Limitations

1. **No client-side abilities** - Only PHP/server-side abilities registered
2. **No feature detection** - AI UI shown regardless of provider capabilities
3. **Single modality** - Text generation only; no image/speech/video support
4. **No pattern preview** - Cannot visualize patterns before page creation
5. **No batch operations** - One page at a time
6. **No REST API** - Abilities only accessible via WP admin
7. **Elayne-only** - Hardcoded to `elayne/` pattern prefix
8. **No prompt templates** - Users must write descriptions from scratch each time

---

## WordPress 7.0 Features to Leverage

Based on [Introducing the AI Client in WordPress 7.0](https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/) and [Client-Side Abilities API](https://make.wordpress.org/core/2026/03/24/client-side-abilities-api-in-wordpress-7-0/):

### AI Client Capabilities

| Feature | Description | Waygate Use Case |
|---------|-------------|------------------|
| `generate_text()` | Text generation | Current: AI page assembly |
| `generate_texts(n)` | Multiple variations | Generate alternate pattern combinations |
| `generate_text_result()` | Rich metadata (tokens, provider, model) | Usage tracking, debugging |
| `generate_image()` | Image generation | Create pattern preview thumbnails |
| `generate_images(n)` | Multiple image variations | A/B test hero images |
| `generate_image_result()` | Image metadata | Track image generation costs |
| `convert_text_to_speech()` | TTS | Audio descriptions of patterns |
| `generate_speech()` | Speech generation | N/A |
| `generate_video()` | Video generation | N/A |
| `is_supported_for_*()` | Feature detection | Hide unsupported features |
| `using_model_preference()` | Model prioritization | Already implemented |
| `using_system_instruction()` | Context setting | Already implemented |
| `using_max_tokens()` | Cost control | Limit AI response length |
| `with_file()` | File input | Upload reference images |
| `with_history()` | Conversation context | Multi-turn pattern refinement |

### Abilities API Enhancements

| Feature | Description | Waygate Use Case |
|---------|-------------|------------------|
| Client-side registration | JS abilities | Inline pattern insertion in editor |
| `@wordpress/abilities` package | Pure state management | Lightweight frontend integration |
| `@wordpress/core-abilities` | Server ability loading | Auto-load server abilities |
| `readonly` annotation | Read-only operations | `list-patterns` ability |
| `destructive` annotation | Destructive operations | N/A |
| `idempotent` annotation | Repeatable operations | `create-page` ability |
| REST API endpoints | HTTP access to abilities | Remote API for Waygate |

---

## Improvement Ideas

### Phase 1: Quick Wins ✅ Complete

#### 1. Add Feature Detection ✅
**File**: `includes/class-ai-integration.php`  
**File**: `includes/class-admin.php`

```php
// Before showing AI UI, check if text generation is supported
$builder = wp_ai_client_prompt( 'test' );
$text_gen_supported = $builder->is_supported_for_text_generation();
$image_gen_supported = $builder->is_supported_for_image_generation();
```

**Benefit**: Hide AI features when no compatible provider is configured.

#### 2. Add Ability Annotations ✅
**File**: `includes/class-abilities-api.php`

```php
wp_register_ability(
    'elayne/list-patterns',
    [
        // ... existing config
        'meta' => [
            'show_in_rest' => true,
            'annotations'   => [
                'readonly' => true,
            ],
        ],
    ]
);

wp_register_ability(
    'elayne/create-page',
    [
        // ... existing config
        'meta' => [
            'show_in_rest' => true,
            'annotations'   => [
                'idempotent' => true,
            ],
        ],
    ]
);
```

**Benefit**: Better REST API behavior, clearer ability semantics.

#### 3. Support Generic Pattern Prefixes ✅
**File**: `includes/class-pattern-lab.php`

```php
// Add filter to allow custom pattern prefixes
public static function get_patterns(): array {
    $prefixes = apply_filters( 'waygate_pattern_prefixes', [ 'elayne/' ] );
    // ... use $prefixes instead of hardcoded 'elayne/'
}
```

**Benefit**: Works with any block theme, not just Elayne.

#### 4. Add Pattern Category Filter in Admin UI ✅
**File**: `includes/class-admin.php`

Add a dropdown to filter patterns by category in the catalog table.

**Benefit**: Easier pattern discovery for users.

### Phase 2: Medium Effort ← **Next Up**

#### 5. Add Image Generation for Pattern Previews
**New file**: `includes/class-image-generator.php`

```php
class ImageGenerator {
    public static function generate_pattern_preview( string $pattern_slug ): array {
        $pattern = self::get_pattern_by_slug( $pattern_slug );
        
        $image = wp_ai_client_prompt(
            "Create a wireframe preview for a block pattern called {$pattern['title']}. " .
            "Show the layout structure with placeholder boxes and labels. " .
            "Clean, minimal design on light background."
        )
            ->using_model_preference( 'gemini-2.0-flash', 'claude-3-haiku' )
            ->as_output_media_orientation( MediaOrientationEnum::landscape() )
            ->generate_image_result();
        
        if ( is_wp_error( $image ) ) {
            return [ 'error' => $image->get_error_message() ];
        }
        
        return [
            'image_uri' => $image->getFile()->getDataUri(),
            'model'     => $image->getModelMetadata()['id'],
            'tokens'    => $image->getTokenUsage(),
        ];
    }
}
```

**UI Integration**: Add preview column to pattern catalog with generated thumbnails.

**Benefit**: Visual pattern selection, better UX.

#### 6. Add REST API Endpoints for Remote Access
**New file**: `includes/class-rest-api.php`

```php
class RestApi {
    public static function init(): void {
        add_action( 'rest_api_init', [ self::class, 'register_endpoints' ] );
    }
    
    public static function register_endpoints(): void {
        register_rest_route(
            'waygate/v1',
            '/patterns',
            [
                'methods'  => 'GET',
                'callback' => [ self::class, 'get_patterns_endpoint' ],
                'permission_callback' => fn() => current_user_can( 'edit_posts' ),
            ]
        );
        
        register_rest_route(
            'waygate/v1',
            '/pages',
            [
                'methods'  => 'POST',
                'callback' => [ self::class, 'create_page_endpoint' ],
                'permission_callback' => fn() => current_user_can( 'publish_pages' ),
            ]
        );
    }
}
```

**Benefit**: Headless WordPress support, external tool integration.

#### 7. Add Client-Side Abilities for Editor Integration
**New file**: `assets/js/abilities.js`

```javascript
import { registerAbility, registerAbilityCategory } from '@wordpress/abilities';

registerAbilityCategory( 'waygate-editor', {
    label: 'Waygate Editor',
    description: 'Pattern insertion and management in the block editor',
} );

registerAbility( {
    name: 'waygate/insert-pattern',
    label: 'Insert Pattern',
    description: 'Insert an Elayne pattern at the current cursor position',
    category: 'waygate-editor',
    input_schema: {
        type: 'object',
        properties: {
            slug: { type: 'string', description: 'Pattern slug to insert' },
        },
        required: [ 'slug' ],
    },
    callback: async ( { slug } ) => {
        const block = wp.blocks.createBlock( 'core/pattern', { slug } );
        wp.data.dispatch( 'core/block-editor' ).insertBlock( block );
        return { success: true, block };
    },
} );
```

**File**: `includes/class-admin.php` (enqueue script)

```php
add_action( 'enqueue_block_editor_assets', function() {
    wp_enqueue_script_module( '@wordpress/core-abilities' );
    wp_enqueue_script( 'waygate-editor-abilities', WAYGATE_PLUGIN_URL . 'assets/js/abilities.js' );
} );
```

**Benefit**: Direct pattern insertion from editor, better integration with Gutenberg.

#### 8. Add Prompt Templates
**File**: `includes/class-ai-integration.php`

```php
private static $prompt_templates = [
    'homepage' => [
        'label'       => 'Homepage',
        'description' => 'A standard homepage with hero, features, testimonials, and CTA',
        'prompt'      => 'Create a homepage for a [industry] business with hero section, features grid, customer testimonials, and a call-to-action section',
    ],
    'about' => [
        'label'       => 'About Page',
        'description' => 'Team bios, company story, and mission statement',
        'prompt'      => 'Create an about page with team member cards, company history, values, and contact form',
    ],
    // ... more templates
];

public static function get_prompt_templates(): array {
    return apply_filters( 'waygate_prompt_templates', self::$prompt_templates );
}
```

**UI Integration**: Add dropdown with templates in admin UI, allow placeholders like `[industry]`.

**Benefit**: Faster onboarding, consistent results.

#### 9. Add Batch Page Creation
**File**: `includes/class-pattern-lab.php`

```php
public static function create_pages_batch( array $page_specs ): array {
    $results = [];
    
    foreach ( $page_specs as $spec ) {
        $result = self::create_page(
            $spec['title'],
            $spec['patterns'],
            $spec['status'] ?? 'draft'
        );
        
        $results[] = [
            'title'   => $spec['title'],
            'success' => ! is_wp_error( $result ),
            'page_id' => is_wp_error( $result ) ? null : $result,
            'error'   => is_wp_error( $result ) ? $result->get_error_message() : null,
        ];
    }
    
    return $results;
}
```

**UI Integration**: Add JSON import/export for page specifications.

**Benefit**: Bulk site creation, import/export patterns.

### Phase 3: Speculative / Aspirational

> These ideas are included for completeness but are not on the near-term roadmap. They should only be pursued once there is concrete user demand — each requires significant effort and introduces external dependencies (embeddings API, speech API, image generation costs) that add operational complexity without a proven use case.

#### 10. Add Pattern to Image Generation (Reverse)
Generate pattern block markup from an image description.

```php
public static function generate_patterns_from_image( string $image_url ): array {
    $description = wp_ai_client_prompt(
        "Analyze this image and describe what block patterns would recreate this layout. " .
        "Return pattern suggestions as a JSON array of pattern slugs."
    )
        ->with_file( $image_url )
        ->as_json_response( [ 'type' => 'array', 'items' => [ 'type' => 'string' ] ] )
        ->generate_text();
    
    return json_decode( $description, true );
}
```

**Benefit**: Visual-to-pattern workflow.

#### 11. Add Pattern Similarity Search
Use embeddings to find similar patterns.

```php
public static function find_similar_patterns( string $description, int $limit = 5 ): array {
    // Use AI to create embedding for description
    // Compare against cached pattern embeddings
    // Return top matches
}
```

**Benefit**: "Show me patterns like this" functionality.

#### 12. Add Voice Pattern Selection
Use speech-to-text for hands-free pattern selection.

```php
public static function voice_to_page( string $audio_url ): array {
    $transcript = wp_ai_client_prompt()
        ->with_file( $audio_url )
        ->convert_speech_to_text();
    
    if ( is_wp_error( $transcript ) ) {
        return [ 'error' => $transcript->get_error_message() ];
    }
    
    return self::generate_page( $transcript );
}
```

**Benefit**: Accessibility, mobile-friendly.

#### 13. Add Cost Tracking
Track token usage and estimated costs.

```php
class CostTracker {
    private static $usage = [];
    
    public static function track_usage( GenerativeAiResult $result ): void {
        $usage = $result->getTokenUsage();
        self::$usage[] = [
            'provider' => $result->getProviderMetadata()['id'],
            'model'    => $result->getModelMetadata()['id'],
            'input_tokens'  => $usage['inputTokens'],
            'output_tokens' => $usage['outputTokens'],
            'timestamp' => time(),
        ];
    }
    
    public static function get_total_usage(): array {
        return [
            'total_input'  => array_sum( wp_list_pluck( self::$usage, 'input_tokens' ) ),
            'total_output' => array_sum( wp_list_pluck( self::$usage, 'output_tokens' ) ),
            'estimated_cost' => self::calculate_estimated_cost(),
        ];
    }
}
```

**UI Integration**: Add usage dashboard to admin.

**Benefit**: Transparency, budget management.

#### 14. Add Pattern Popularity Tracking
Track which patterns are most used.

```php
public static function track_pattern_usage( string $pattern_slug ): void {
    $counts = get_option( 'waygate_pattern_usage', [] );
    $counts[ $pattern_slug ] = ( $counts[ $pattern_slug ] ?? 0 ) + 1;
    update_option( 'waygate_pattern_usage', $counts );
}
```

**UI Integration**: Sort patterns by popularity in catalog.

**Benefit**: Help users discover popular patterns.

---

## Technical Considerations

### Dependencies

| Feature | Dependency | Status |
|---------|------------|--------|
| Image generation | WP AI Client 7.0+ | Available |
| Client-side abilities | `@wordpress/abilities` | Available in WP 7.0 |
| REST API abilities | WP 7.0 Abilities API | Available |
| Token usage tracking | WP AI Client | Available |
| Model preferences | WP AI Client | Already used |

### Compatibility

- Requires WordPress 7.0+ for full feature set
- Graceful degradation for WP 6.5+ (current requirement)
- Feature detection for AI capabilities

### Performance

- Image generation can be expensive (tokens, time)
- Consider caching generated previews
- Lazy load pattern previews
- Rate limiting for batch operations

---

## Implementation Priority

### Recommended Order

1. ~~Feature detection, ability annotations, generic prefixes — *Phase 1*~~ ✅ Done
2. Prompt templates — *Phase 2* (1–2 days) ← **Start here**
3. REST API endpoints — *Phase 2* (2–3 days)
4. Client-side abilities for editor integration — *Phase 2* (2–3 days)
5. Image generation for previews — *Phase 2* (2–3 days)
6. Batch page creation — *Phase 2* (1–2 days)
7. Cost tracking, pattern popularity — *Phase 3* (2–3 days)
8. Advanced features based on user feedback — *Phase 3, speculative*

### Quick Start — Phase 1 Complete ✅

All four Phase 1 items shipped as of 2026-05-24:

- ~~Add feature detection~~ ✅
- ~~Add ability annotations~~ ✅
- ~~Add generic pattern prefix filter~~ ✅
- ~~Add category filter to admin UI~~ ✅

---

## API Reference

### WordPress 7.0 AI Client

```php
// Text generation
$text = wp_ai_client_prompt( 'Prompt' )
    ->using_temperature( 0.7 )
    ->using_model_preference( 'model-1', 'model-2' )
    ->generate_text();

// Image generation
$image = wp_ai_client_prompt( 'Prompt' )
    ->as_output_media_orientation( MediaOrientationEnum::landscape() )
    ->generate_image_result();

// Feature detection
$builder = wp_ai_client_prompt( 'test' );
if ( $builder->is_supported_for_image_generation() ) {
    // Show image generation UI
}

// Rich result with metadata
$result = wp_ai_client_prompt( 'Prompt' )
    ->generate_text_result();

$usage = $result->getTokenUsage();
$provider = $result->getProviderMetadata();
$model = $result->getModelMetadata();
```

### Abilities API (PHP)

```php
wp_register_ability(
    'namespace/action',
    [
        'label'        => 'Action Label',
        'description'  => 'Action description',
        'category'     => 'category-slug',
        'input_schema' => [ /* JSON Schema */ ],
        'output_schema' => [ /* JSON Schema */ ],
        'execute_callback' => function( array $params ) {
            // Logic here
            return [ 'success' => true ];
        },
        'permission_callback' => fn() => current_user_can( 'capability' ),
        'meta' => [
            'show_in_rest' => true,
            'annotations' => [
                'readonly'    => true,
                'idempotent' => true,
            ],
        ],
    ]
);
```

### Abilities API (JavaScript)

```javascript
import { 
    registerAbility, 
    registerAbilityCategory,
    executeAbility,
    getAbilities 
} from '@wordpress/abilities';

// Register
registerAbilityCategory( 'category', { label: 'Label', description: 'Desc' } );
registerAbility( { name: 'action', label: 'Label', category: 'category', callback: async () => ({}) } );

// Execute
const result = await executeAbility( 'namespace/action', { param: 'value' } );

// Query
const abilities = getAbilities();
```

---

## Success Metrics

> **Note:** None of these are currently tracked. Before treating any target as meaningful, the measurement mechanism must be implemented first — see Phase 2 (Cost Tracking, #13) and Phase 3 (Pattern Popularity, #14). Until then, treat these as directional goals, not KPIs.

| Metric | Target | How to measure (not yet implemented) |
|--------|--------|--------------------------------------|
| AI page generation success rate | 95%+ | Log success/failure in `generate_page()`, store in a WP option or custom table |
| Pattern catalog usage | 80% of users | Hook into admin page views; correlate with user count |
| Average patterns per page | 4–6 | Log pattern count per `create_page()` call |
| Token usage per generation | < 5000 | Requires Phase 2 Cost Tracker (#13) |
| User satisfaction | 4.5/5 | Manual user surveys; no automated path |

---

## Resources

- [WordPress 7.0 AI Client Documentation](https://make.wordpress.org/core/2026/03/24/introducing-the-ai-client-in-wordpress-7-0/)
- [Client-Side Abilities API](https://make.wordpress.org/core/2026/03/24/client-side-abilities-api-in-wordpress-7-0/)
- [PHP AI Client GitHub](https://github.com/WordPress/php-ai-client)
- [WP AI Client GitHub](https://github.com/WordPress/wp-ai-client)
- [Abilities API Original Proposal](https://make.wordpress.org/core/2025/11/10/abilities-api-in-wordpress-6-9/)

---

## Changelog

| Date | Author | Change |
|------|--------|--------|
| 2026-05-22 | Initial draft | Created roadmap document |
| 2026-05-24 | Jasper Frumau | Marked Phase 1 complete; Phase 2 is next |
