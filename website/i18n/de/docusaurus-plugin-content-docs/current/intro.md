---
id: intro
title: Was ist Weaver ORM?
sidebar_label: Einführung
slug: /
---

Weaver ORM ist ein PHP 8.4+ Object-Relational Mapper (objektrelationaler Mapper) für Symfony-Anwendungen, der auf einem einzigen Grundsatz aufbaut: **Ihre Domain-Objekte sollen keinerlei Kenntnisse über die Datenbank besitzen**. Keine Annotationen auf Entity-Klassen, keine Proxy-Generierung, keine Laufzeit-Reflection – nur einfache PHP-Objekte und explizite Mapper-Klassen, die die Übersetzung zwischen ihnen und SQL übernehmen.

## Die Probleme, die Weaver löst

### Doctrine-Proxy-Objekte (Proxy-Klassen)

Doctrine umhüllt jede verknüpfte Entity mit einer Proxy-Klasse, die Zugriffe auf Eigenschaften abfängt, um beim ersten Zugriff eine SQL-Abfrage auszulösen. In traditionellen Request/Response-Zyklen ist das unsichtbar, ermöglicht aber stillschweigend N+1-Abfragemuster und macht das Debuggen verwirrend (`var_dump($post->getAuthor())` gibt einen Proxy aus, kein `User`-Objekt).

In dauerhaft laufenden PHP-Workers (RoadRunner, FrankenPHP, Swoole, Symfony Messenger) häuft der `EntityManager` zwischen Anfragen veralteten Zustand an und muss an jeder Request-Grenze manuell zurückgesetzt werden – ein leicht zu machender Fehler, der schwer zu diagnostizieren ist.

### Reflection-basierte Hydration (Datenbankzeilen zu Objekten)

Doctrine verwendet `ReflectionProperty`, um private/geschützte Eigenschaften direkt auf Entity-Objekte zu setzen und dabei Ihre Domain-Logik zu umgehen. Bei jeder Anfrage müssen PHP-Attribute neu geparst oder aus einem warmen Cache geladen werden; Proxy-Klassen müssen auf der Festplatte vorhanden sein.

### Unbegrenzte Identity Map (Identitätskarte)

Der Doctrine `EntityManager` hält jede geladene Entity für die Dauer der Anfrage im Speicher. Das Laden großer Ergebnismengen verursacht unbegrenztes Speicherwachstum. Das Gegenmittel – `$em->clear()` – trennt alles ab, einschließlich Entities, die Sie vergessen haben erneut zu persistieren.

## Was Weaver anders macht

Weaver basiert auf vier Grundprinzipien:

1. **Einfache PHP-Objekte als Entities.** Ihre `User`-Klasse hat null ORM-Abhängigkeiten. Keine Attribute, keine Basisklasse, kein Interface. Es ist ein reines Value Object oder Domain-Objekt, das Sie ohne Symfony-Boot und ohne Datenbankverbindung unit-testen können.

2. **Explizite Mapper-Klassen.** Eine separate `UserMapper`-Klasse beschreibt, wie `User` auf die `users`-Tabelle abgebildet wird. Spaltentypen, Beziehungen, Primärschlüssel – alles an einem Ort, alles in reinem PHP, vollständig durchsuchbar und statisch analysierbar.

3. **Keine Proxies, kein implizites Lazy Loading.** Beziehungen werden immer explizit über `->with(['relation'])` geladen. Sie wissen immer genau, welches SQL wann ausgeführt wird.

4. **Worker-sicher von Grund auf.** Mapper sind zustandslos und werden einmal pro Worker-Prozess geladen. Jede HTTP-Anfrage oder jeder Messenger-Job erhält seinen eigenen `EntityWorkspace` (Unit of Work), sodass kein gemeinsamer veränderlicher Zustand zwischen Anfragen besteht.

## Wichtigste Unterschiede auf einen Blick

| Merkmal | Doctrine ORM | Weaver ORM |
|---|---|---|
| Proxy-Klassen-Generierung | Erforderlich | Nicht notwendig |
| Laufzeit-Reflection | Ja | Niemals |
| Lazy Loading | Implizit (Proxy) | Nur explizit |
| Entity-Annotationen/Attribute | Auf der Entity-Klasse | Separate Mapper-Klasse |
| Worker-Prozess-Neustart beim Zurücksetzen | Ja | Nein |
| N+1-Prävention | Manueller `JOIN FETCH` | Erzwungen durch `with()` |
| Speicher pro 10.000 Zeilen | ~48 MB | ~11 MB |
| Hydration-Zeit für 10.000 Zeilen | ~420 ms | ~95 ms |
| PHPStan / statische Analyse | Teilweise (Magic-Proxies) | Vollständig (explizite Mapper) |

> Benchmarks: PHP 8.4, PostgreSQL 16, Ubuntu 22.04, 10.000 `User`-Zeilen mit einer `Profile`-Beziehung. Ergebnisse variieren je nach Hardware und Abfragekomplexität.

## Architekturübersicht

```
Entity (einfache PHP-Klasse – keinerlei ORM-Kopplung)
    │
    └── Mapper (Tabellenname, Spalten, Beziehungen, hydrate/extract)
            │
            └── EntityWorkspace → QueryBuilder → PDO/DBAL
```

Der `EntityWorkspace` ersetzt Doctrines `EntityManager`. Es ist eine anfragebeschränkte Unit of Work, die verfolgt, welche Entities eingefügt, aktualisiert oder gelöscht werden müssen, wenn `flush()` aufgerufen wird. Da er anfragebeschränkt ist, gibt es keinen Identity-Map-Speicherleck zwischen Anfragen.

## PyroSQL-Unterstützung

Weaver wird mit optionaler Unterstützung für **PyroSQL** geliefert, einer hochleistungsfähigen In-Process-Analyse-SQL-Engine. PyroSQL kann als Read-Replica für Aggregationsabfragen, Berichte und große Datensätze verwendet werden, ohne die primäre relationale Datenbank zu belasten. Weitere Details finden Sie im [PyroSQL-Abschnitt](/pyrosql).

## Anforderungen

| Abhängigkeit | Mindestversion |
|---|---|
| PHP | 8.4 |
| Symfony | 7.0 |
| doctrine/dbal | 4.0 (nur Verbindungsschicht) |
| MySQL | 8.0 |
| PostgreSQL | 14 |
| SQLite | 3.35 |

Optional:

- `symfony/messenger` – asynchrone Event-Veröffentlichung und Outbox-Muster
- `symfony/cache` – Caching von Abfrageergebnissen
- `mongodb/mongodb` + `ext-mongodb` – MongoDB-Dokumentmapper-Unterstützung

## Was Weaver nicht ist

Weaver ist kein direkter Ersatz für Doctrine. Wenn Sie stark auf Doctrines DQL, Criteria-API oder attributbasierte Migrationen angewiesen sind, müssen Sie diese Schicht neu schreiben. Weaver eignet sich am besten für **Greenfield-Symfony-7+-Projekte** oder **Anwendungen, die von Doctrine migriert werden** und explizites, vorhersehbares SQL sowie worker-sichere Persistenz wünschen.
