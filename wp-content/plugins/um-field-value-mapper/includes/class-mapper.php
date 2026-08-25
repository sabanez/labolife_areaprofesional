<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class UMFVM_Mapper {

    /** [ field_key => [ form_id => [ label => mapped_value ] ] ] */
    private static $index = array();

    /** form_id capturado del shortcode activo */
    private static $active_form_id = null;

    public static function init() {
        $all = get_option( UMFVM_OPTION, array() );
        if ( empty( $all ) ) {
            return;
        }

        foreach ( $all as $form_id => $fields ) {
            foreach ( $fields as $fk => $map ) {
                self::$index[ $fk ][ (string) $form_id ] = $map;
            }
        }

        foreach ( array_keys( self::$index ) as $fk ) {
            self::register_hooks( $fk );
        }

        // Captura el form_id activo desde los args del shortcode de UM,
        // antes de que los filtros de campo se ejecuten.
        add_filter( 'um_pre_args_setup', array( __CLASS__, 'capture_form_id' ) );
        add_action( 'um_before_form',    array( __CLASS__, 'capture_form_id_from_action' ) );

        // ── Guardado en WordPress ────────────────────────────────────────────
        // UM valida el valor enviado contra las opciones "en crudo" registradas
        // en el formulario (labels), no contra el value mapeado que se envía
        // realmente en el POST. Si no coinciden, UM descarta el valor y el
        // campo se guarda vacío. Estos hooks NO tocan el envío a HubSpot (que
        // ya recibe el value mapeado correctamente vía los filtros de arriba);
        // solo hacen que WordPress también persista el valor correcto.

        // Registro: UM valida contra $form_data['custom_fields'] sin pasar por
        // los filtros de arriba, así que traducimos ida y vuelta alrededor de
        // esa validación (prioridad 10 en class-validation.php).
        add_filter( 'um_before_save_filter_submitted', array( __CLASS__, 'presave_swap' ), 9, 3 );
        add_filter( 'um_before_save_filter_submitted', array( __CLASS__, 'postsave_restore' ), 11, 3 );

        // Edición de perfil: UM reconstruye las opciones del campo desde el
        // formulario y valida el value enviado contra ellas. Inyectamos el
        // value mapeado en esas opciones para que la validación lo acepte
        // directamente (el value ya llega mapeado desde el navegador).
        add_filter( 'um_user_edit_profile_fields', array( __CLASS__, 'inject_mapped_options' ), 10, 3 );
    }

    /**
     * Determina el mapa a usar para un campo dado un form_id conocido
     * (viene de $form_data['form_id'], no depende del estado de renderizado).
     */
    private static function resolve_map( $fk, $form_id ) {
        if ( $form_id && isset( self::$index[ $fk ][ (string) $form_id ] ) ) {
            return self::$index[ $fk ][ (string) $form_id ];
        }
        return self::get_map_any( $fk );
    }

    /**
     * Aplica una tabla de traducción (label<->value) a un valor de campo,
     * que puede ser un string (radio/select) o un array (checkbox/multiselect).
     */
    private static function translate_value( $value, $lookup ) {
        if ( is_array( $value ) ) {
            foreach ( $value as $k => $v ) {
                if ( is_string( $v ) && array_key_exists( $v, $lookup ) ) {
                    $value[ $k ] = $lookup[ $v ];
                }
            }
            return $value;
        }
        if ( is_string( $value ) && array_key_exists( $value, $lookup ) ) {
            return $lookup[ $value ];
        }
        return $value;
    }

    /**
     * Antes de que UM valide (prioridad 10): convierte el value mapeado
     * recibido en el POST de vuelta al label original, para que pase la
     * validación de UM (que compara contra las opciones registradas).
     */
    public static function presave_swap( $changes, $args, $form_data ) {
        $form_id = isset( $form_data['form_id'] ) ? (string) $form_data['form_id'] : null;
        foreach ( self::$index as $fk => $per_form ) {
            if ( ! isset( $changes[ $fk ] ) ) {
                continue;
            }
            $map = self::resolve_map( $fk, $form_id );
            if ( ! $map ) {
                continue;
            }
            $changes[ $fk ] = self::translate_value( $changes[ $fk ], array_flip( $map ) );
        }
        return $changes;
    }

    /**
     * Después de que UM valide (prioridad 11): vuelve a convertir el label
     * (ya validado) en el value mapeado, que es lo que debe quedar guardado
     * en WordPress.
     */
    public static function postsave_restore( $changes, $args, $form_data ) {
        $form_id = isset( $form_data['form_id'] ) ? (string) $form_data['form_id'] : null;
        foreach ( self::$index as $fk => $per_form ) {
            if ( ! isset( $changes[ $fk ] ) ) {
                continue;
            }
            $map = self::resolve_map( $fk, $form_id );
            if ( ! $map ) {
                continue;
            }
            $changes[ $fk ] = self::translate_value( $changes[ $fk ], $map );
        }
        return $changes;
    }

    /**
     * Edición de perfil: sustituye los labels por los values mapeados dentro
     * de las opciones que UM usa para validar el value enviado. El value
     * enviado por el navegador ya es el value mapeado, así que basta con que
     * la lista de opciones "permitidas" también use esos values.
     */
    public static function inject_mapped_options( $fields, $args, $form_data ) {
        if ( empty( $fields ) || ! is_array( $fields ) ) {
            return $fields;
        }
        $form_id = isset( $form_data['form_id'] ) ? (string) $form_data['form_id'] : null;
        foreach ( self::$index as $fk => $per_form ) {
            if ( empty( $fields[ $fk ]['options'] ) || ! is_array( $fields[ $fk ] ) ) {
                continue;
            }
            $map = self::resolve_map( $fk, $form_id );
            if ( ! $map ) {
                continue;
            }
            $options = $fields[ $fk ]['options'];
            if ( ! is_array( $options ) ) {
                continue;
            }
            foreach ( $options as $k => $label ) {
                if ( is_string( $label ) && array_key_exists( $label, $map ) ) {
                    $options[ $k ] = $map[ $label ];
                }
            }
            $fields[ $fk ]['options'] = $options;
        }
        return $fields;
    }

    public static function capture_form_id( $args ) {
        if ( ! empty( $args['form_id'] ) ) {
            self::$active_form_id = (string) $args['form_id'];
        }
        return $args;
    }

    public static function capture_form_id_from_action( $args ) {
        if ( ! empty( $args['form_id'] ) ) {
            self::$active_form_id = (string) $args['form_id'];
        }
    }

    private static function register_hooks( $fk ) {

        // ── Radio ──────────────────────────────────────────────────────────
        // Hace que UM use la clave del array como value del input
        add_filter( 'um_radio_options_pair__' . $fk, '__return_true' );

        // Reordena el array a [ mapped_value => label ]
        add_filter( 'um_radio_field_options_' . $fk, function( $options ) use ( $fk ) {
            $map = UMFVM_Mapper::get_map( $fk );
            if ( ! $map ) {
                return $options;
            }
            $out = array();
            foreach ( $options as $label ) {
                $val       = array_key_exists( $label, $map ) ? $map[ $label ] : $label;
                $out[ $val ] = $label;
            }
            return $out;
        } );

        // ── Checkbox ───────────────────────────────────────────────────────
        // Cambia el value de cada opción al valor mapeado
        add_filter( 'um_checkbox_field_options_' . $fk, function( $options ) use ( $fk ) {
            $map = UMFVM_Mapper::get_map( $fk );
            if ( ! $map ) {
                return $options;
            }
            foreach ( $options as $k => $label ) {
                $options[ $k ] = array_key_exists( $label, $map ) ? $map[ $label ] : $label;
            }
            return $options;
        } );

        // Restaura el label visible en español cuando UM renderiza el ítem seleccionado
        add_filter( 'um_field_checkbox_item_title', function( $title, $key, $v, $data ) use ( $fk ) {
            if ( $key !== $fk ) {
                return $title;
            }
            $map = UMFVM_Mapper::get_map( $fk );
            if ( ! $map ) {
                return $title;
            }
            $reverse = array_flip( $map );
            return isset( $reverse[ $v ] ) ? $reverse[ $v ] : $title;
        }, 10, 4 );

        // ── Select ─────────────────────────────────────────────────────────
        // Equivalente a um_radio_options_pair__ pero para select: le dice a UM
        // que use la clave del array como value del <option>.
        // Es un hook global sin field_key — devolver true para todos es seguro
        // porque los selects sin mapeo ya tienen key === label.
        add_filter( 'um_select_options_pair', '__return_true' );

        // Hook para selects dinámicos — ya sabemos el campo por el nombre del hook,
        // usamos get_map_any() que no depende del form_id.
        add_filter( 'um_select_dropdown_dynamic_options_' . $fk, function( $options ) use ( $fk ) {
            $map = UMFVM_Mapper::get_map_any( $fk );
            if ( ! $map ) {
                return $options;
            }
            $out = array();
            foreach ( $options as $val => $label ) {
                $new_val         = array_key_exists( $label, $map ) ? $map[ $label ] : $val;
                $out[ $new_val ] = $label;
            }
            return $out;
        } );

        // Hook para selects estáticos — hook global, UM pasa el field_key como segundo parámetro.
        add_filter( 'um_selectbox_options', function( $options, $key ) use ( $fk ) {
            if ( $key !== $fk ) {
                return $options;
            }
            $map = UMFVM_Mapper::get_map_any( $fk );
            if ( ! $map ) {
                return $options;
            }
            $out = array();
            foreach ( $options as $val => $label ) {
                $new_val         = array_key_exists( $label, $map ) ? $map[ $label ] : $val;
                $out[ $new_val ] = $label;
            }
            return $out;
        }, 10, 2 );
    }

    /**
     * Devuelve el mapa combinado de todos los formularios que tienen este campo.
     * Útil en hooks ya específicos de campo donde el form_id es irrelevante.
     */
    public static function get_map_any( $fk ) {
        if ( ! isset( self::$index[ $fk ] ) ) {
            return null;
        }
        $merged = array();
        foreach ( self::$index[ $fk ] as $map ) {
            $merged = array_merge( $merged, $map );
        }
        return $merged ?: null;
    }

    /**
     * Devuelve el mapa [ label => mapped_value ] para el campo y el formulario
     * que se está renderizando en este momento, o null si no hay mapeo.
     */
    public static function get_map( $fk ) {
        if ( ! isset( self::$index[ $fk ] ) ) {
            return null;
        }

        $form_id = self::current_form_id();

        if ( $form_id && isset( self::$index[ $fk ][ $form_id ] ) ) {
            return self::$index[ $fk ][ $form_id ];
        }

        // Si no se puede determinar el formulario activo pero el campo
        // solo tiene un mapeo configurado, se aplica directamente.
        $maps = self::$index[ $fk ];
        if ( count( $maps ) === 1 ) {
            return reset( $maps );
        }

        return null;
    }

    private static function current_form_id() {
        // Fuente 1: capturado del shortcode (más fiable)
        if ( ! empty( self::$active_form_id ) ) {
            return self::$active_form_id;
        }

        if ( ! function_exists( 'UM' ) ) {
            return null;
        }

        // Fuente 2: propiedad directa de UM
        if ( ! empty( UM()->form()->form_id ) ) {
            return (string) UM()->form()->form_id;
        }

        // Fuente 3: método get()
        if ( method_exists( UM()->form(), 'get' ) ) {
            $id = UM()->form()->get( 'form_id' );
            if ( ! empty( $id ) ) {
                return (string) $id;
            }
        }

        // Fuente 4: attrs del shortcode almacenados en UM
        if ( ! empty( UM()->form()->attrs['form_id'] ) ) {
            return (string) UM()->form()->attrs['form_id'];
        }

        return null;
    }
}
