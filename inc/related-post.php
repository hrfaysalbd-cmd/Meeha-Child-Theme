<?php
/**
 * Meeha News - Repeating Inline Related Posts
 *
 * Shows a related/popular/random post at a fixed paragraph interval.
 *
 * Priority:
 * 1. Same tags + same category
 * 2. Same category
 * 3. Popular post
 * 4. Random post
 *
 * Default interval: Every 5 paragraphs
 */


/* =========================================================
 * SETTINGS
 * ========================================================= */

/*
 * Number of paragraphs between related-post cards.
 *
 * Example:
 * 5 = card after paragraph 5, 10, 15, 20...
 */
define( 'MEEHA_RELATED_POST_INTERVAL', 5 );


/* =========================================================
 * FIND A POST
 * ========================================================= */

function meeha_get_inline_related_post( $current_post_id, $exclude_ids = array() ) {

    $categories = wp_get_post_categories( $current_post_id );

    $tags = wp_get_post_tags(
        $current_post_id,
        array(
            'fields' => 'ids',
        )
    );

    /*
     * Always exclude current post.
     */
    $exclude_ids[] = $current_post_id;

    $exclude_ids = array_unique(
        array_map( 'intval', $exclude_ids )
    );


    /* =====================================================
     * 1. SAME TAGS + SAME CATEGORY
     * ===================================================== */

    if ( ! empty( $categories ) && ! empty( $tags ) ) {

        $query = new WP_Query(
            array(
                'post_type'           => 'post',
                'post_status'         => 'publish',
                'posts_per_page'      => 1,
                'post__not_in'        => $exclude_ids,
                'category__in'        => $categories,
                'tag__in'             => $tags,
                'orderby'             => 'date',
                'order'               => 'DESC',
                'ignore_sticky_posts' => true,
                'no_found_rows'       => true,
            )
        );

        if ( $query->have_posts() ) {
            $query->the_post();

            $post_data = array(
                'id'    => get_the_ID(),
                'title' => get_the_title(),
                'link'  => get_permalink(),
                'image' => get_the_post_thumbnail_url(
                    get_the_ID(),
                    'medium'
                ),
            );

            wp_reset_postdata();

            return $post_data;
        }

        wp_reset_postdata();
    }


    /* =====================================================
     * 2. SAME CATEGORY
     * ===================================================== */

    if ( ! empty( $categories ) ) {

        $query = new WP_Query(
            array(
                'post_type'           => 'post',
                'post_status'         => 'publish',
                'posts_per_page'      => 1,
                'post__not_in'        => $exclude_ids,
                'category__in'        => $categories,
                'orderby'             => 'date',
                'order'               => 'DESC',
                'ignore_sticky_posts' => true,
                'no_found_rows'       => true,
            )
        );

        if ( $query->have_posts() ) {
            $query->the_post();

            $post_data = array(
                'id'    => get_the_ID(),
                'title' => get_the_title(),
                'link'  => get_permalink(),
                'image' => get_the_post_thumbnail_url(
                    get_the_ID(),
                    'medium'
                ),
            );

            wp_reset_postdata();

            return $post_data;
        }

        wp_reset_postdata();
    }


    /* =====================================================
     * 3. POPULAR POST
     * ===================================================== */

    $query = new WP_Query(
        array(
            'post_type'           => 'post',
            'post_status'         => 'publish',
            'posts_per_page'      => 1,
            'post__not_in'        => $exclude_ids,
            'orderby'             => 'comment_count',
            'order'               => 'DESC',
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
        )
    );

    if ( $query->have_posts() ) {

        $query->the_post();

        $post_data = array(
            'id'    => get_the_ID(),
            'title' => get_the_title(),
            'link'  => get_permalink(),
            'image' => get_the_post_thumbnail_url(
                get_the_ID(),
                'medium'
            ),
        );

        wp_reset_postdata();

        return $post_data;
    }

    wp_reset_postdata();


    /* =====================================================
     * 4. RANDOM POST
     * ===================================================== */

    $query = new WP_Query(
        array(
            'post_type'           => 'post',
            'post_status'         => 'publish',
            'posts_per_page'      => 1,
            'post__not_in'        => $exclude_ids,
            'orderby'             => 'rand',
            'ignore_sticky_posts' => true,
            'no_found_rows'       => true,
        )
    );

    if ( $query->have_posts() ) {

        $query->the_post();

        $post_data = array(
            'id'    => get_the_ID(),
            'title' => get_the_title(),
            'link'  => get_permalink(),
            'image' => get_the_post_thumbnail_url(
                get_the_ID(),
                'medium'
            ),
        );

        wp_reset_postdata();

        return $post_data;
    }

    wp_reset_postdata();

    return false;
}


/* =========================================================
 * BUILD RELATED CARD
 * ========================================================= */

function meeha_build_related_card( $post_data ) {

    if ( empty( $post_data ) ) {
        return '';
    }

    ob_start();
    ?>

    <div class="meeha-inline-related">

        <div class="meeha-related-heading">
            আরও পড়ুন
        </div>

        <a
            class="meeha-related-card"
            href="<?php echo esc_url( $post_data['link'] ); ?>"
            aria-label="<?php echo esc_attr( $post_data['title'] ); ?>"
        >

            <?php if ( ! empty( $post_data['image'] ) ) : ?>

                <div class="meeha-related-thumb">

                    <img
                        src="<?php echo esc_url( $post_data['image'] ); ?>"
                        alt="<?php echo esc_attr( $post_data['title'] ); ?>"
                        loading="lazy"
                    >

                </div>

            <?php endif; ?>

            <div class="meeha-related-content">

                <div class="meeha-related-title">
                    <?php echo esc_html( $post_data['title'] ); ?>
                </div>

            </div>

        </a>

    </div>

    <?php

    return ob_get_clean();
}


/* =========================================================
 * INSERT MULTIPLE RELATED POSTS
 * ========================================================= */

function meeha_news_inline_related_posts( $content ) {

    /*
     * Only normal single posts.
     */
    if (
        ! is_single()
        || ! is_main_query()
        || ! in_the_loop()
    ) {
        return $content;
    }

    global $post;

    if ( empty( $post->ID ) ) {
        return $content;
    }

    $current_post_id = $post->ID;

    /*
     * Split article into paragraphs.
     */
    $paragraphs = explode( '</p>', $content );

    /*
     * Do nothing if the article is too short.
     */
    if ( count( $paragraphs ) <= MEEHA_RELATED_POST_INTERVAL ) {
        return $content;
    }

    /*
     * Keep track of already displayed posts.
     *
     * Prevents the same related article from
     * appearing repeatedly in the same article.
     */
    $displayed_posts = array();

    $new_content = '';

    /*
     * Count actual paragraph blocks.
     */
    $paragraph_count = 0;

    foreach ( $paragraphs as $paragraph ) {

        /*
         * Add paragraph.
         */
        if ( trim( $paragraph ) !== '' ) {

            $new_content .= $paragraph . '</p>';

            $paragraph_count++;
        }


        /*
         * Insert related card every X paragraphs.
         */
        if (
            $paragraph_count > 0
            && $paragraph_count % MEEHA_RELATED_POST_INTERVAL === 0
        ) {

            $related_post = meeha_get_inline_related_post(
                $current_post_id,
                $displayed_posts
            );

            if ( $related_post ) {

                /*
                 * Remember this post so it won't be
                 * repeated later in the same article.
                 */
                $displayed_posts[] = $related_post['id'];

                /*
                 * Insert card.
                 */
                $new_content .= meeha_build_related_card(
                    $related_post
                );
            }
        }
    }

    return $new_content;
}

add_filter(
    'the_content',
    'meeha_news_inline_related_posts',
    20
);


/* =========================================================
 * CSS
 * ========================================================= */

function meeha_news_inline_related_styles() {

    if ( ! is_single() ) {
        return;
    }

    ?>

    <style id="meeha-inline-related-style">

        .meeha-inline-related {
            width: 100%;
            margin: 28px 0;
        }

        .meeha-related-heading {
            margin-bottom: 10px;
            font-size: 16px;
            line-height: 1.4;
            font-weight: 600;
            color: #222;
        }

        .meeha-related-card {
            display: flex;
            align-items: center;
            width: 100%;
            box-sizing: border-box;

            gap: 20px;

            padding: 12px 14px;

            border: 1px solid #ddd;

            background: #fff;

            text-decoration: none !important;

            transition:
                border-color 0.2s ease,
                box-shadow 0.2s ease;
        }

        .meeha-related-card:hover {
            border-color: #aaa;

            box-shadow:
                0 2px 8px rgba(0, 0, 0, 0.06);
        }

        .meeha-related-thumb {
            flex: 0 0 190px;

            width: 190px;
            height: 105px;

            overflow: hidden;
        }

        .meeha-related-thumb img {
            display: block;

            width: 100%;
            height: 100%;

            object-fit: cover;
        }

        .meeha-related-content {
            flex: 1;
            min-width: 0;
        }

        .meeha-related-title {
            margin: 0;

            color: #222;

            font-size: 20px;
            line-height: 1.5;

            font-weight: 500;
        }

        .meeha-related-card:hover
        .meeha-related-title {
            color: #111;
        }


        /* =========================
         * TABLET
         * ========================= */

        @media (max-width: 768px) {

            .meeha-related-card {
                gap: 14px;
            }

            .meeha-related-thumb {
                flex-basis: 150px;

                width: 150px;
                height: 90px;
            }

            .meeha-related-title {
                font-size: 18px;
                line-height: 1.45;
            }
        }


        /* =========================
         * MOBILE
         * ========================= */

        @media (max-width: 480px) {

            .meeha-inline-related {
                margin: 22px 0;
            }

            .meeha-related-card {
                gap: 12px;
                padding: 9px;
            }

            .meeha-related-thumb {
                flex-basis: 105px;

                width: 105px;
                height: 72px;
            }

            .meeha-related-title {
                font-size: 16px;
                line-height: 1.4;
            }

            .meeha-related-heading {
                font-size: 15px;
            }
        }

    </style>

    <?php
}

add_action(
    'wp_head',
    'meeha_news_inline_related_styles',
    100
);
?>