# UM Field Value Mapper

Mu-plugin de Labolife para Ultimate Member (UM). Permite que un campo de un
formulario UM muestre al usuario un label en español, pero envíe/guarde un
value distinto (normalmente el value interno que espera HubSpot), sin tener
que crear el campo con esos values directamente en UM.

## Para qué sirve

Los formularios de registro de UM alimentan directamente los formularios de
HubSpot (vía `hubspot-mapper`). HubSpot exige valores internos concretos en
inglés/código (`"Endocrinology"`, `"true"`, `"Pharmacies"`...) para sus
propiedades, pero el formulario debe mostrarse en español al usuario
(`"Endocrinología"`, `"Sí"`, `"Farmacéutico"`...).

En vez de duplicar cada campo problemático o gestionar la traducción a mano,
este plugin permite definir, por formulario y por campo, un mapa
`label en español => value interno` desde **Ultimate Member → Field Value
Mapper** en el admin. La configuración se guarda en la opción
`umfvm_mappings` (`wp_options`).

## Cómo interfiere con Ultimate Member

UM no está diseñado para que un campo tenga un value distinto del label que
el usuario ve. Este plugin lo consigue enganchándose a varios filtros
internos de UM, con efectos colaterales importantes que hay que tener en
cuenta al depurar cualquier problema de visualización o guardado de campos:

### 1. Renderizado del formulario (radio/checkbox/select)

- `um_radio_options_pair__{campo}`, `um_checkbox_field_options_{campo}`,
  `um_selectbox_options`, `um_select_dropdown_dynamic_options_{campo}`:
  reordenan las opciones del campo a `[value_interno => label]` en vez de
  `[label => label]`, para que el `<input>`/`<option>` generado por UM lleve
  el value interno pero muestre el label en español.
- **`um_select_options_pair` se registra en `__return_true` de forma
  GLOBAL, sin acotar a ningún campo concreto.** Esto le dice a UM que TODO
  select de TODO formulario del sitio debe tratarse como "pares
  value≠label", incluso los que no tienen ningún mapeo configurado. Es un
  riesgo latente: cualquier lógica de UM (presente o futura) que dependa de
  este filtro para decidir cómo interpretar `$data['options']` se ve
  afectada globalmente, no solo para los campos mapeados.

### 2. Guardado en WordPress (registro y edición de perfil)

- `um_before_save_filter_submitted` (dos hooks, en prioridad 9 y 11):
  UM valida el value enviado contra los labels registrados en el
  formulario. Como el navegador ya envía el value interno (no el label),
  el plugin lo traduce a label justo antes de la validación de UM
  (prioridad 9) y lo vuelve a traducir a value interno justo después
  (prioridad 11), para que lo que quede guardado en `usermeta` sea el
  value interno.
- `um_user_edit_profile_fields`: en edición de perfil, UM reconstruye las
  opciones del campo y valida contra ellas; el plugin inyecta el value
  interno en esas opciones para que la validación lo acepte directamente.

### 3. Visualización del value ya guardado (perfil y listado de miembros)

Este es el punto más frágil y el que ha dado más problemas. UM muestra el
value guardado a través de `um_filtered_value()`
(`wp-content/plugins/ultimate-member/includes/um-short-functions.php`),
que aplica, en este orden, tres cadenas de filtros:

```
um_profile_field_filter_hook__            (sin sufijo)
um_profile_field_filter_hook__{$key}      (por campo)
um_profile_field_filter_hook__{$type}     (por tipo: select, radio...)
```

El núcleo de UM registra en la primera cadena (`um_profile_field_filter_hook__`,
prioridad 99, en `um-filters-fields.php`,
función `um_profile_field_filter_xss_validation`) una validación que, para
`select`/`radio`, comprueba que el value guardado esté entre las opciones
registradas del campo:

```php
$option_pairs = apply_filters( 'um_select_options_pair', null, $data );
$arr = $option_pairs ? array_keys( $data['options'] ) : $data['options'];
if ( ! in_array( $value, $arr ) ) {
    $value = '';   // UM vacía el value si no lo reconoce
}
```

Como `um_select_options_pair` está forzado a `true` globalmente (ver punto
1) y `$data['options']` es la lista plana de labels en español (sin values
internos, porque esos solo se generan en tiempo de renderizado del
formulario, no se guardan en la definición del campo), UM compara el value
interno guardado (p. ej. `"Endocrinology"`) contra los ÍNDICES numéricos del
array de opciones (`[0,1,2...31]`). Nunca coincide, así que **UM vacía el
value a `''` en su propia validación interna, antes de que este plugin
tenga ninguna oportunidad de traducirlo de vuelta al label en español**.

**Fix aplicado (esta versión):** el plugin engancha además
`um_profile_field_filter_hook__{$campo}` y, en vez de fiarse del `$value`
que le llega (que ya puede venir vaciado por UM), **relee el value crudo
directamente de `usermeta`** con `get_user_meta( $user_id, $campo, true )`
y lo traduce él mismo con el mapa configurado. Esto evita depender de que
la validación interna de UM haya dejado el value intacto.

⚠️ **Cuidado con arrays**: `um_filtered_value()` siempre espera un string de
vuelta — al final llama a `UM()->shortcodes()->emotize()`, que hace
`stripslashes()` sin comprobar el tipo, y **revienta con un fatal error si
recibe un array** (esto rompió el listado de miembros para todos los
usuarios la primera vez que se desplegó este fix, por un usuario con un
value heredado guardado como array en vez de string). Por eso, si la
traducción devuelve un array, se convierte a texto con `implode(', ', ...)`
antes de devolverlo.

## Dónde tocar si algo se rompe

| Síntoma | Dónde mirar |
|---|---|
| El formulario no muestra las opciones en español | `register_hooks()` en `includes/class-mapper.php`, hooks de renderizado (`um_radio_field_options_*`, `um_selectbox_options`, etc.) |
| El value no se guarda en WordPress (queda vacío) | `presave_swap()` / `postsave_restore()` en `includes/class-mapper.php` |
| El campo no aparece o aparece vacío en el perfil/listado de miembros | El filtro `um_profile_field_filter_hook__{campo}` en `register_hooks()`. Sospecha primero de `um_profile_field_filter_xss_validation` (núcleo de UM) vaciando el value antes de que este plugin actúe |
| Error fatal en el listado de miembros (`stripslashes(): array given`, o similar) | Algún campo tiene guardado un array en vez de un string en `usermeta`; revisar el `implode()` de seguridad en el filtro de arriba y el dato real en BBDD |
| Un campo mapeado en un formulario, pero no está en la lista de campos a mostrar del directorio | No es cosa de este plugin — revisar `_um_reveal_fields` del `um_directory` correspondiente y la caché `wp_options.um_fields` |

## Configuración

Panel de administración: **Ultimate Member → Field Value Mapper**
(`admin.php?page=umfvm-settings`). Los mapas se guardan en la opción
`umfvm_mappings`, con esta forma:

```php
[
    <form_id> => [
        '<campo>' => [
            '<Label en español>' => '<value interno>',
            ...
        ],
    ],
]
```
