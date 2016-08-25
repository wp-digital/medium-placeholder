<?php

class Medium_Placeholder_Generator {

    public function get_sizes() {
        global $_wp_additional_image_sizes;

        $sizes = [];

        foreach ( get_intermediate_image_sizes() as $_size ) {
            if ( in_array( $_size, ['thumbnail', 'medium', 'medium_large', 'large'] ) ) {
                $sizes[ $_size ]['width']  = get_option( "{$_size}_size_w" );
                $sizes[ $_size ]['height'] = get_option( "{$_size}_size_h" );
                $sizes[ $_size ]['crop']   = boolval( get_option( "{$_size}_crop" ) );
            }
            elseif ( isset( $_wp_additional_image_sizes[ $_size ] ) ) {
                $sizes[ $_size ] = [
                    'width'  => $_wp_additional_image_sizes[ $_size ]['width'],
                    'height' => $_wp_additional_image_sizes[ $_size ]['height'],
                    'crop'   => $_wp_additional_image_sizes[ $_size ]['crop'],
                ];
            }
        }

        return $sizes;
    }

    protected function _get_image_ratio( $id, $size ) {
        if ( is_array( $size ) ) {
            return round( $size[0] / $size[1] * 100 );
        }

        $sizes = $this->get_sizes();

        if ( array_key_exists( $size, $sizes ) ) {
            $image_size = $sizes[ $size ];
            if ( $image_size['crop'] ) {
                return round( $image_size['width'] / $image_size['height'] * 100 );
            }
        }

        $meta = wp_get_attachment_metadata( $id );
        if ( empty ( $meta ) ) {
            return false;
        }

        return round( $meta['width'] / $meta['height'] * 100 );
    }

    protected function _get_file_by_ratio( $id, $ratio ) {

        $file = get_attached_file( $id );

        if ( empty ( $file ) ) {
            return false;
        }

        $meta = wp_get_attachment_metadata( $id );

        if ( ! empty ( $meta ) ) {
            foreach ( $meta['sizes'] as $size ) {
                $size_ratio = round( $size['width'] / $size['height'] * 100 );
                if ( $ratio == $size_ratio ) {
                    return dirname( $file ) . DIRECTORY_SEPARATOR . $size['file'];
                }
            }
        }

        return $file;
    }


    protected function _get_image_thumbnail_blob( $file ) {

        if ( ! is_file( $file ) ) {
            return false;
        }

        // try local imagick
        if ( extension_loaded( 'imagick' ) && class_exists( 'Imagick', false ) ) {
            $image = new Imagick( $file );

            if (! $image->valid() ) {
                return false;
            }

            if ( method_exists( $image, 'setIteratorIndex' ) ) {
                $image->setIteratorIndex( 0 );
            }
            $image->setImageBackgroundColor( apply_filters( 'medium_placeholder_background_color', '#ffffff', $file ) );
            $image = $image->flattenImages();

            $image->stripImage();
            $image->setImageCompressionQuality( 20 );
            $image->setImageCompression( imagick::COMPRESSION_JPEG );

            $image->thumbnailImage( 30, 0 );

            $image->setImageFormat( 'jpeg' );
            $blob = $image->getImageBlob();
            $image->clear();
            $image->destroy();

            return $blob;
        }

        // try local GD
        if ( extension_loaded( 'gd' ) && function_exists( 'gd_info' ) ) {
            // todo: GB implementation
        }

        // use default wp image editor
        $editor = wp_get_image_editor( $file );

        if ( is_wp_error( $editor ) ) {
            return false;
        }

        $editor->set_quality( 20 );
        $editor->resize( 30, 200, false );

        require_once( ABSPATH . 'wp-admin/includes/file.php' );
        $tmp_name = wp_tempnam() . '.jpg';

        $saved = $editor->save( $tmp_name , 'image/jpeg' );

        if ( is_wp_error( $saved ) ) {
            return false;
        }

        $blob = "";
        $file = fopen( $saved['path'], 'rb' );
        while ( ! feof( $file ) ) {
            $blob .= fgets( $file );
        }
        fclose( $file );

        return $blob;
    }

    protected function _get_image_placeholder( $id, $size ) {

        $ratio = $this->_get_image_ratio( $id, $size );

        if ( false === $ratio ) {
            return false;
        }

        $key = '_medium_placeholder_' . $ratio;

        if ( '' == ( $placeholder = get_post_meta( $id, $key, true ) ) ) {

            $lock_key = 'lock_' . $key;

            if ( get_transient( $lock_key ) ) {
                return false;
            }

            set_transient( $lock_key, '1', MINUTE_IN_SECONDS );

            $file = $this->_get_file_by_ratio( $id, $ratio );

            if ( $file ) {

                $blob = $this->_get_image_thumbnail_blob( $file );

                if ( $blob ) {
                    $placeholder = 'data:image/jpeg;base64,' . base64_encode( $blob );
                }
                else {
                    $placeholder = '_invalid_';
                }

            }
            else {
                $placeholder = '_invalid_';
            }

            update_post_meta( $id, $key, $placeholder );
            delete_transient( $lock_key );
        }

        return $placeholder;
    }

    public function remove_placeholders( $attachment_id = null ) {
        global $wpdb;

        $where = "WHERE `meta_key` LIKE '_medium_placeholder_%'";
        if ( ! empty ( $attachment_id ) ) {
            $where .= $wpdb->prepare( " AND post_id = %d", intval( $attachment_id ) );
        }

        $results = $wpdb->get_results( "SELECT post_id, meta_key FROM {$wpdb->postmeta} $where" );

        foreach ( $results as $row ) {
            delete_post_meta( $row->post_id, $row->meta_key );
        }
    }

    public function replace_image_html( $html, $attachment_id, $size ) {

        if ( ! preg_match( '/^\<img/i', $html ) ) {
            return $html;
        }

        $is_need_placeholder = apply_filters( 'medium_placeholder_image_valid', true, $html, $attachment_id, $size );
        if ( ! $is_need_placeholder ) {
            return $html;
        }

        $placeholder = $this->_get_image_placeholder( $attachment_id, $size );

        if ( empty ( $placeholder ) || '_invalid_' == $placeholder ) {
            return $html;
        }

        $matches = null;
        preg_match_all( '/(\S+)=["\']?((?:.(?!["\']?\s+(?:\S+)=|[>"\']))+.)["\']?/', $html, $matches );

        $attributes = [];
        if ( ! empty ( $matches ) ) {
            foreach ( $matches[1] as $index => $key ) {
                $attributes[ strtolower( $key ) ] = $matches[2][$index];
            }
        }

        $ratio = $this->_get_image_ratio( $attachment_id, $size );

        if ( false == $ratio ) {
            return $html;
        }

        $canvas_width = 60;
        $canvas_height = round( $canvas_width * 100 / $ratio );

        $padding_bottom = round( 100 * 100 / $ratio, 5 );


        $image_classes = isset ( $attributes['class'] ) ? ' ' . $attributes['class'] : '';
        $image_attributes = [];
        $exclude_attributes = ['src', 'srcset', 'id', 'width', 'height', 'class', 'style', 'onload'];
        foreach ( $attributes as $key => $value ) {
            if ( ! in_array( $key, $exclude_attributes ) ) {
                $image_attributes[] = "$key=\"$value\"";
            }
        }
        $image_attributes = implode( '', $image_attributes );

        if ( empty ( $attributes['src'] ) ) {
            return $html;
        }

        $src = $attributes['src'];
        $srcSet = isset ( $attributes['srcset'] ) ? $attributes['srcset'] : '';
        $attrSizes = isset ( $attributes['sizes'] ) ? $attributes['sizes'] : '';

        return "<span class=\"medium-placeholder\" data-src=\"{$src}\" data-srcset=\"$srcSet\" data-sizes=\"$attrSizes\">
                <span class=\"medium-placeholder-fill\" style='padding-bottom: $padding_bottom%'></span>
                <img class=\"medium-placeholder-thumbnail\" onload=\"mediumPlaceholder(this)\" src=\"{$placeholder}\" $image_attributes >
                <canvas class=\"medium-placeholder-canvas\" width=\"{$canvas_width}\" height=\"{$canvas_height}\"></canvas>
                <noscript>{$html}</noscript>
        </span>";
    }

}