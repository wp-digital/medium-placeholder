<?php
/*
 * Plugin Name: Medium Placeholder
 * Version: 0.1.0
 * Description: Beautiful image placeholders as on Medium.com
 * Author: Redink AS
 * Author URI: http://www.redink.no/
 * License:  GPL v2 or later
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 */

require_once __DIR__ . '/class-medium-placeholder-generator.php';

$medium_placeholder_generator = new Medium_Placeholder_Generator();


add_action( 'edit_attachment', 'medium_placeholder_remove_placeholders' );
function medium_placeholder_remove_placeholders( $attachment_id ) {
    global $medium_placeholder_generator;
    $medium_placeholder_generator->remove_placeholders( $attachment_id );
}

register_deactivation_hook( __FILE__, 'medium_placeholder_remove_all_placeholders' );
function medium_placeholder_remove_all_placeholders() {
    global $medium_placeholder_generator;
    $medium_placeholder_generator->remove_placeholders();
}

add_filter( 'post_thumbnail_html', 'medium_placeholder_post_thumbnail_html_filter', 101, 5);
function medium_placeholder_post_thumbnail_html_filter( $html, $post_id, $post_thumbnail_id, $size, $attr ) {
    global $medium_placeholder_generator;
    return $medium_placeholder_generator->replace_image_html( $html, $post_thumbnail_id, $size );
}

add_filter( 'the_content', 'medium_placeholder_the_content_filter', 101 );
function medium_placeholder_the_content_filter ( $content ) {
    return preg_replace_callback( '/<img.*?wp-image-(\d+).*?\/?>/', function ( $match ) {
        $img = $match[0];
        $id = intval( $match[1] );

        $m = null;
        if ( preg_match( '/class=.*?size-([a-z]+)/', $img, $m ) ) {
            $size = $m[1];
            global $medium_placeholder_generator;
            return $medium_placeholder_generator->replace_image_html( $img, $id, $size );
        }

        return $match[0];
    }, $content );
}

add_action( 'wp_print_styles', 'medium_placeholder_wp_print_styles_action' );
function medium_placeholder_wp_print_styles_action() {
    wp_register_style( 'medium-placeholder', plugins_url( '/assets/medium.placeholder.css', __FILE__ ) );
    wp_enqueue_style( 'medium-placeholder');
}

add_action( 'wp_enqueue_scripts', 'medium_placeholder_wp_enqueue_scripts_action' );
function medium_placeholder_wp_enqueue_scripts_action() {
    wp_register_script( 'medium-placeholder', plugins_url( '/assets/medium.placeholder.min.js', __FILE__ ) );
    wp_enqueue_script( 'medium-placeholder' );
}




























