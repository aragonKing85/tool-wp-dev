# TOOL-WP-DEV — Plugin de Herramientas para WordPress

## ¿Qué es este proyecto?
Un plugin de WordPress que agrupa múltiples herramientas de desarrollo en un solo lugar. Funciona como una **web app moderna dentro del escritorio de WordPress**. Las herramientas son módulos independientes que se pueden ir añadiendo con el tiempo.

### Herramientas actuales
- `md-redirects` — Gestión de redirecciones
- `blog-migrator` — Migración de posts
- `converter-post-cpt` — Conversión de posts a CPT
- `polylang-fix-simulator` — Simulador/fixer para Polylang
- `modules/web-inspector` — Inspección web

---

## Estructura del proyecto

```
tool-wp-dev/
├── tool-wp-dev.php          ← Archivo principal (loader)
├── modules/
│   └── shared/              ← Recursos comunes reutilizables
│       ├── helpers.php      ← Funciones de utilidad compartidas
│       ├── ui-components.php← Componentes HTML reutilizables
│       └── security.php     ← Funciones de seguridad comunes
├── [nombre-herramienta]/
│   ├── [nombre].php         ← Lógica principal del módulo
│   ├── views/               ← HTML/plantillas de la herramienta
│   └── assets/              ← CSS/JS específicos (si los necesita)
└── assets/
    ├── css/
    │   └── admin-global.css ← Estilos globales del plugin
    └── js/
        └── admin-global.js  ← JS global del plugin
```

**Regla:** Si una función se usa en 2 o más herramientas → va a `modules/shared/`.

---

## Principios de código

### 1. Simplicidad ante todo
- Código claro y directo. Sin over-engineering.
- Funciones pequeñas con una sola responsabilidad.
- Si algo se puede hacer simple, no lo compliques.

### 2. Reutilización
- Antes de escribir una función nueva, revisar si ya existe en `modules/shared/`.
- Los helpers comunes (sanitización, mensajes de estado, render de tablas, etc.) siempre van en shared.
- Los estilos y JS globales del admin van en `assets/` del plugin raíz, no duplicados en cada módulo.

### 3. Seguridad — OBLIGATORIO en todo el código
Siempre aplicar estas reglas sin excepción:

```php
// ✅ Verificar que estamos en WordPress
if ( ! defined( 'ABSPATH' ) ) exit;

// ✅ Verificar nonce en formularios
check_admin_referer( 'twd_accion_nombre' );

// ✅ Verificar permisos del usuario
if ( ! current_user_can( 'manage_options' ) ) {
    wp_die( __( 'No tienes permiso.', 'tool-wp-dev' ) );
}

// ✅ Sanitizar SIEMPRE los inputs
$valor = sanitize_text_field( $_POST['campo'] );

// ✅ Escapar SIEMPRE los outputs
echo esc_html( $valor );
echo esc_url( $url );
echo wp_kses_post( $html_confiable );

// ✅ Usar $wpdb con placeholders
$wpdb->prepare( "SELECT * FROM {$wpdb->prefix}tabla WHERE id = %d", $id );
```

**Nunca usar:**
- `$_POST` o `$_GET` directamente sin sanitizar
- `echo` de variables sin escapar
- Queries SQL con concatenación directa

### 4. Vue 3 — Framework de interfaz — OBLIGATORIO

**Este proyecto usa Vue 3 (CDN) en el frontend del admin. No usar JS vanilla para la UI. No eliminar Vue. No sustituirlo.**

#### Cómo se carga Vue
PHP registra el CDN y lo encola solo en las pantallas del plugin:
```php
wp_enqueue_script( 'vue', 'https://unpkg.com/vue@3/dist/vue.global.prod.js', [], '3', true );
wp_enqueue_script( 'twd-mi-herramienta', TWD_URL . 'mi-herramienta/assets/app.js', ['vue'], TWD_VERSION, true );
```

#### Estructura de cada herramienta con Vue
```
mi-herramienta/
└── assets/
    └── app.js   ← App Vue montada sobre #twd-app
```

El HTML del `views/settings.php` solo necesita el punto de montaje:
```html
<div id="twd-app"></div>
```

#### Cómo se estructura el app.js
```js
const { createApp, ref, computed, onMounted } = Vue;

createApp({
  setup() {
    // estado reactivo con ref()
    // llamadas AJAX con fetch()
    // datos pasados desde PHP via twdData (wp_localize_script)
    return { /* exponer al template */ };
  },
  template: `...`  // o usar componentes
}).mount('#twd-app');
```

#### Comunicación PHP → Vue
Pasar datos desde PHP con `wp_localize_script()`:
```php
wp_localize_script( 'twd-mi-herramienta', 'twdData', [
  'ajaxUrl' => admin_url( 'admin-ajax.php' ),
  'nonce'   => wp_create_nonce( 'twd_mi_nonce' ),
  'data'    => $datos_iniciales,
]);
```
En Vue se accede como `window.twdData`.

#### Reglas Vue en este proyecto
- **Siempre Vue** para interfaces con estado, listas, formularios o acciones AJAX.
- **No mezclar** jQuery o JS vanilla para manipular el DOM donde Vue ya gestiona la UI.
- Los componentes reutilizables entre herramientas van en `assets/js/components/`.
- Usar `ref()` y `computed()` para el estado. No usar `data()` (Options API).
- Las llamadas AJAX se hacen con `fetch()`, nunca con `$.ajax()`.

---

### 5. Diseño — web app moderna en el admin
- El diseño debe sentirse como una app moderna, **no como un plugin genérico de WordPress**.
- Usar CSS variables para colores, tipografía y espaciado.
- Layout con sidebar izquierdo (menú de herramientas) + área de contenido principal.
- Responsive para tabletas (el escritorio de WP también se usa en iPad).
- Componentes visuales: cards, badges de estado, tablas limpias, botones con estados (loading, success, error).
- Preferir transiciones suaves sobre cambios bruscos.
- Paleta coherente en todas las herramientas del plugin.

### 6. Documentación en el código
Todo archivo y función debe estar documentado:

```php
<?php
/**
 * Nombre del archivo — Descripción breve de qué hace.
 *
 * @package Tool_WP_Dev
 * @module  nombre-herramienta
 */

/**
 * Descripción de qué hace la función.
 *
 * @param  string $param  Descripción del parámetro.
 * @return bool           Descripción de lo que devuelve.
 */
function twd_mi_funcion( $param ) {
    // ...
}
```

---

## Cómo añadir una nueva herramienta

1. Crear carpeta `nombre-herramienta/` en la raíz del plugin.
2. Crear el archivo PHP principal: `nombre-herramienta/nombre-herramienta.php`.
3. Añadir el `require_once` en `tool-wp-dev.php`.
4. Registrar el submenú con `add_submenu_page()` dentro de la herramienta.
5. En el `views/settings.php` añadir el punto de montaje: `<div id="twd-app"></div>`.
6. Crear `assets/app.js` con la app Vue montada sobre `#twd-app`.
7. Encolar Vue CDN + el `app.js` con `wp_enqueue_script()`.
8. Pasar datos de PHP a Vue con `wp_localize_script()`.
9. Usar los helpers de `modules/shared/` para seguridad, UI y utilidades.
10. Documentar el archivo con el bloque de cabecera estándar.

---

## Prefijos y nomenclatura

- **PHP functions:** `twd_` → ej: `twd_render_dashboard()`
- **PHP classes:** `TWD_` → ej: `class TWD_Redirects`
- **CSS classes:** `twd-` → ej: `.twd-card`, `.twd-btn`
- **JS variables/functions:** `twd` → ej: `twdInitTable()`
- **Nonces:** `twd_accion_descripcion` → ej: `twd_save_redirect`
- **Options en DB:** `twd_` → ej: `twd_redirects_list`

---

## Checklist antes de dar por terminado un módulo

- [ ] `if ( ! defined( 'ABSPATH' ) ) exit;` al inicio del archivo
- [ ] Nonce verificado en todas las acciones de formulario
- [ ] `current_user_can()` verificado
- [ ] Todos los inputs sanitizados
- [ ] Todos los outputs escapados
- [ ] Funciones comunes movidas a `shared/` si aplica
- [ ] Código documentado con PHPDoc
- [ ] Diseño coherente con el resto del plugin
- [ ] Vue 3 cargado vía CDN con `wp_enqueue_script()`
- [ ] App Vue montada sobre `#twd-app`
- [ ] Datos PHP pasados a Vue con `wp_localize_script()`
- [ ] Sin `console.log` ni código de debug en producción