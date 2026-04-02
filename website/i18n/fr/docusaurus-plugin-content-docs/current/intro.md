---
id: intro
title: Qu'est-ce que Weaver ORM ?
sidebar_label: Introduction
slug: /
---

Weaver ORM est un mappeur objet-relationnel PHP 8.4+ pour les applications Symfony, construit sur un seul principe : **vos objets de domaine ne doivent avoir aucune connaissance de la base de données**. Pas d'annotations sur les classes entité, pas de génération de proxy, pas de réflexion à l'exécution — uniquement des objets PHP simples et des classes de mapping explicites qui font le lien entre eux et le SQL.

## Les problèmes que Weaver résout

### Les objets proxy de Doctrine

Doctrine enveloppe chaque entité liée dans une classe proxy qui intercepte l'accès aux propriétés pour déclencher une requête SQL au premier accès. Dans les cycles requête/réponse traditionnels, cela est invisible, mais cela autorise silencieusement les schémas de requêtes N+1 et rend le débogage confus (`var_dump($post->getAuthor())` affiche un proxy, pas un `User`).

Dans les workers PHP à longue durée de vie (RoadRunner, FrankenPHP, Swoole, Symfony Messenger), l'`EntityManager` accumule un état périmé entre les requêtes et doit être réinitialisé manuellement à chaque frontière de requête — une erreur facile à commettre et un bug difficile à diagnostiquer.

### Hydratation par réflexion

Doctrine utilise `ReflectionProperty` pour définir directement des propriétés privées/protégées sur les objets entité, contournant ainsi votre logique de domaine. Chaque requête doit re-analyser les attributs PHP ou accéder à un cache chaud ; les classes proxy doivent exister sur le disque.

### Identity map non bornée

L'`EntityManager` de Doctrine conserve en mémoire chaque entité chargée pendant toute la durée de la requête. Le chargement de grands ensembles de résultats provoque une croissance mémoire non bornée. La solution de contournement — `$em->clear()` — détache tout, y compris les entités que vous avez oublié de re-persister.

## Ce que Weaver fait différemment

Weaver est construit sur quatre principes :

1. **Des objets PHP simples comme entités.** Votre classe `User` n'a aucune dépendance ORM. Pas d'attributs, pas de classe de base, pas d'interface. C'est un pur objet valeur ou objet de domaine que vous pouvez tester unitairement sans démarrer Symfony.

2. **Des classes de mapping explicites.** Une classe `UserMapper` séparée décrit comment `User` se mappe sur la table `users`. Types de colonnes, relations, clés primaires — tout en un seul endroit, tout en PHP simple, entièrement consultable via grep et analysable statiquement.

3. **Pas de proxy, pas de chargement différé implicite.** Les relations sont toujours chargées explicitement via `->with(['relation'])`. Vous savez toujours exactement quel SQL est exécuté et quand.

4. **Conçu pour les workers.** Les mappers sont sans état et chargés une fois par processus worker. Chaque requête HTTP ou job Messenger obtient son propre `EntityWorkspace` (unité de travail), il n'y a donc pas d'état mutable partagé entre les requêtes.

## Différenciateurs clés en un coup d'œil

| Fonctionnalité | Doctrine ORM | Weaver ORM |
|---|---|---|
| Génération de classes proxy | Requise | Non nécessaire |
| Réflexion à l'exécution | Oui | Jamais |
| Chargement différé | Implicite (proxy) | Explicite uniquement |
| Annotations/attributs d'entité | Sur la classe entité | Classe mapper séparée |
| Redémarrage du processus worker lors de la réinitialisation | Oui | Non |
| Prévention N+1 | `JOIN FETCH` manuel | Imposé par `with()` |
| Mémoire pour 10 000 lignes | ~48 Mo | ~11 Mo |
| Temps d'hydratation pour 10 000 lignes | ~420 ms | ~95 ms |
| PHPStan / analyse statique | Partielle (proxies magiques) | Complète (mappers explicites) |

> Benchmarks : PHP 8.4, PostgreSQL 16, Ubuntu 22.04, 10 000 lignes `User` avec une relation `Profile`. Les résultats varient selon le matériel et la complexité des requêtes.

## Vue d'ensemble de l'architecture

```
Entité (classe PHP simple — zéro couplage ORM)
    │
    └── Mapper (nom de table, colonnes, relations, hydrate/extract)
            │
            └── EntityWorkspace → QueryBuilder → PDO/DBAL
```

L'`EntityWorkspace` remplace l'`EntityManager` de Doctrine. C'est une unité de travail à portée de requête qui suit les entités devant être insérées, mises à jour ou supprimées lors de l'appel à `flush()`. Comme elle est à portée de requête, il n'y a pas de fuite d'identity map entre les requêtes.

## Support PyroSQL

Weaver est livré avec un support optionnel pour **PyroSQL**, un moteur SQL analytique haute performance en cours de processus. PyroSQL peut être utilisé comme réplica de lecture pour les requêtes d'agrégation, les rapports et les opérations sur de grands ensembles de données sans toucher à la base de données relationnelle principale. Voir la [section PyroSQL](/pyrosql) pour plus de détails.

## Prérequis

| Dépendance | Version minimale |
|---|---|
| PHP | 8.4 |
| Symfony | 7.0 |
| doctrine/dbal | 4.0 (couche de connexion uniquement) |
| MySQL | 8.0 |
| PostgreSQL | 14 |
| SQLite | 3.35 |

Optionnel :

- `symfony/messenger` — publication d'événements asynchrones et patron outbox
- `symfony/cache` — mise en cache des résultats de requêtes
- `mongodb/mongodb` + `ext-mongodb` — support du mappeur de documents MongoDB

## Ce que Weaver n'est pas

Weaver n'est pas un remplacement drop-in pour Doctrine. Si vous dépendez fortement du DQL de Doctrine, de l'API Criteria, ou des migrations basées sur les attributs, vous devrez réécrire cette couche. Weaver est mieux adapté aux **projets Symfony 7+ greenfield** ou aux **applications en cours de migration depuis Doctrine** qui souhaitent un SQL explicite, prévisible et une persistance sûre pour les workers.
