# Changelog - tema Impreza (area profesional Labo'life)

## 2026-11-11
- `style.css`: en `/login/` (movil, <=600px) el boton "Registrarme" pasa a mostrarse
  encima del titulo "¿Aun no te has inscrito?" (flex + `order: -1`, solo visual).
  Limite: depende de la clase `.title-registro` del titulo y de `:has()`.
  Prueba: abrir /login/ en movil y comprobar que el boton aparece sin scroll.

- `style.css`: en `/registro/` (page-id 1417, movil <=600px) los acordeones "Registro de
  farmacias" y "Registro profesionales" pasan a mostrarse justo debajo del titulo de
  bienvenida y encima del bloque oscuro de texto (flex column + `order: -1`, solo visual).
  Limite: depende de la clase `.left-column-register` y de `:has()`; si se duplica la
  pagina el `page-id` cambia.
  Prueba: abrir /registro/ en movil y comprobar que los botones se ven sin scroll.

- `style.css`: en `/registro/` (movil <=600px) el bloque "¿Ya estas registrado? / Inicia
  sesion" ya no queda al final de la pagina (tras el bloque oscuro largo): sube justo debajo
  de los acordeones. Orden: logo, titulo, acordeones, inicia sesion, bloque oscuro.
  Implementacion: `display:contents` en los contenedores de WPBakery de la seccion del
  formulario + `#page-content` flex column + `order`; se repone a mano el padding perdido.
  Reemplaza el reorden anterior de `/registro/` (`.g-cols:has(> .left-column-register)`).
  Limite: muy dependiente de la estructura/clases de WPBakery (`.left-column-register`,
  `.us_custom_bb436424`, ultima seccion = inicia sesion); revisar si se edita la pagina.

- `style.css`: en el formulario "Registro profesionales" (movil <=600px) el campo "Tipo de
  profesional" muestra una opcion por linea (antes Doctor / Farmaceutico / Profesional de la
  salud se solapaban). La regla antigua `div.um-1414.uimob340 .um-field-half.um-field-radio`
  (width:30%) se anula solo para `.um-field-contact_labolife_professional_area`.
  Prueba: abrir /registro/ en movil, desplegar "Registro profesionales" y comprobar el campo.
  Nota: los radios de "Titulo" y "Si/No" siguen en varias columnas; si tambien se quieren
  una por linea, se amplia el selector.
