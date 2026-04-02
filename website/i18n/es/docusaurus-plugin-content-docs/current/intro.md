---
id: intro
title: ¿Qué es Weaver ORM?
sidebar_label: Introducción
slug: /
---

Weaver ORM es un mapeador objeto-relacional para PHP 8.4+ en aplicaciones Symfony construido sobre una premisa única: **tus objetos de dominio no deben tener ningún conocimiento de la base de datos**. Sin anotaciones en las clases de entidad, sin generación de proxies, sin reflexión en tiempo de ejecución — solo objetos PHP simples y clases mapper explícitas que traducen entre ellos y SQL.

## Los problemas que Weaver resuelve

### Objetos proxy de Doctrine

Doctrine envuelve cada entidad relacionada en una clase proxy que intercepta el acceso a las propiedades para disparar una consulta SQL en el primer acceso. En ciclos tradicionales de petición/respuesta esto es invisible, pero habilita silenciosamente patrones de consultas N+1 y hace que la depuración sea confusa (`var_dump($post->getAuthor())` imprime un proxy, no un `User`).

En workers PHP de larga duración (RoadRunner, FrankenPHP, Swoole, Symfony Messenger), el `EntityManager` acumula estado obsoleto entre peticiones y debe reiniciarse manualmente en cada límite de petición — un error fácil de cometer y un bug difícil de diagnosticar.

### Hidratación basada en reflexión

Doctrine usa `ReflectionProperty` para establecer propiedades privadas/protegidas directamente en los objetos de entidad, omitiendo tu lógica de dominio. Cada petición debe volver a analizar los atributos PHP o acceder a una caché caliente; las clases proxy deben existir en el disco.

### Mapa de identidad sin límites

El `EntityManager` de Doctrine mantiene cada entidad cargada en memoria durante la duración de la petición. Cargar grandes conjuntos de resultados provoca un crecimiento de memoria sin límites. La solución — `$em->clear()` — desconecta todo, incluyendo las entidades que olvidaste volver a persistir.

## Lo que Weaver hace de manera diferente

Weaver está construido sobre cuatro principios:

1. **Objetos PHP simples como entidades.** Tu clase `User` no tiene dependencias del ORM. Sin atributos, sin clase base, sin interfaz. Es un objeto de valor o de dominio puro que puedes probar unitariamente sin arrancar Symfony.

2. **Clases mapper explícitas.** Una clase `UserMapper` separada describe cómo `User` se mapea a la tabla `users`. Tipos de columnas, relaciones, claves primarias — todo en un lugar, todo en PHP simple, completamente buscable con grep y analizable estáticamente.

3. **Sin proxies, sin carga perezosa implícita.** Las relaciones siempre se cargan explícitamente mediante `->with(['relation'])`. Siempre sabes exactamente qué SQL se ejecuta y cuándo.

4. **Seguro para workers por diseño.** Los mappers no tienen estado y se cargan una vez por proceso worker. Cada petición HTTP o trabajo de Messenger obtiene su propio `EntityWorkspace` (unidad de trabajo), por lo que no hay estado mutable compartido entre peticiones.

## Diferenciadores clave de un vistazo

| Característica | Doctrine ORM | Weaver ORM |
|---|---|---|
| Generación de clases proxy | Requerida | No necesaria |
| Reflexión en tiempo de ejecución | Sí | Nunca |
| Carga perezosa | Implícita (proxy) | Solo explícita |
| Anotaciones/atributos de entidad | En la clase de entidad | Clase mapper separada |
| Reinicio de proceso worker en reset | Sí | No |
| Prevención de N+1 | `JOIN FETCH` manual | Forzada por `with()` |
| Memoria por 10k filas | ~48 MB | ~11 MB |
| Tiempo de hidratación para 10k filas | ~420 ms | ~95 ms |
| PHPStan / análisis estático | Parcial (proxies mágicos) | Completo (mappers explícitos) |

> Benchmarks: PHP 8.4, PostgreSQL 16, Ubuntu 22.04, 10 000 filas de `User` con una relación `Profile`. Los resultados varían según el hardware y la complejidad de las consultas.

## Descripción general de la arquitectura

```
Entity (clase PHP simple — sin acoplamiento al ORM)
    │
    └── Mapper (nombre de tabla, columnas, relaciones, hydrate/extract)
            │
            └── EntityWorkspace → QueryBuilder → PDO/DBAL
```

El `EntityWorkspace` reemplaza al `EntityManager` de Doctrine. Es una unidad de trabajo con ámbito de petición que rastrea qué entidades necesitan ser insertadas, actualizadas o eliminadas cuando se llama a `flush()`. Como tiene ámbito de petición, no hay fuga del mapa de identidad entre peticiones.

## Soporte para PyroSQL

Weaver incluye soporte opcional para **PyroSQL**, un motor SQL analítico de alto rendimiento en proceso. PyroSQL puede usarse como réplica de lectura para consultas de agregación, reportes y operaciones con grandes conjuntos de datos sin tocar la base de datos relacional primaria. Consulta la [sección de PyroSQL](/pyrosql) para más detalles.

## Requisitos

| Dependencia | Versión mínima |
|---|---|
| PHP | 8.4 |
| Symfony | 7.0 |
| doctrine/dbal | 4.0 (solo capa de conexión) |
| MySQL | 8.0 |
| PostgreSQL | 14 |
| SQLite | 3.35 |

Opcional:

- `symfony/messenger` — publicación de eventos asíncrona y patrón outbox
- `symfony/cache` — caché de resultados de consultas
- `mongodb/mongodb` + `ext-mongodb` — soporte de mapper de documentos MongoDB

## Lo que Weaver no es

Weaver no es un reemplazo directo de Doctrine. Si dependes mucho del DQL de Doctrine, la API de criterios, o las migraciones basadas en atributos, necesitarás reescribir esa capa. Weaver es más adecuado para **proyectos Symfony 7+ desde cero** o **aplicaciones que se están migrando desde Doctrine** que quieren SQL explícito, predecible y persistencia segura para workers.
