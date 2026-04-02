---
id: intro
title: O que é o Weaver ORM?
sidebar_label: Introdução
slug: /
---

O Weaver ORM é um mapeador objeto-relacional (ORM) para PHP 8.4+ em aplicações Symfony construído sobre uma única premissa: **seus objetos de domínio não devem ter nenhum conhecimento do banco de dados**. Sem anotações nas classes de entidade, sem geração de proxies, sem reflexão em tempo de execução — apenas objetos PHP simples e classes de mapeamento explícitas que traduzem entre eles e o SQL.

## Os problemas que o Weaver resolve

### Objetos proxy do Doctrine

O Doctrine envolve cada entidade relacionada em uma classe proxy (proxy class) que intercepta o acesso às propriedades para disparar uma consulta SQL na primeira utilização. Em ciclos tradicionais de requisição/resposta isso é invisível, mas silenciosamente viabiliza padrões de consulta N+1 e torna a depuração confusa (`var_dump($post->getAuthor())` exibe um proxy, não um `User`).

Em workers PHP de longa duração (RoadRunner, FrankenPHP, Swoole, Symfony Messenger) o `EntityManager` acumula estado obsoleto entre requisições e precisa ser redefinido manualmente a cada fronteira de requisição — um erro fácil de cometer e um bug difícil de diagnosticar.

### Hidratação baseada em reflexão

O Doctrine usa `ReflectionProperty` para definir propriedades privadas/protegidas diretamente nos objetos de entidade, contornando sua lógica de domínio. Cada requisição precisa reanalisar atributos PHP ou acessar um cache quente; classes proxy precisam existir em disco.

### Mapa de identidade ilimitado

O `EntityManager` do Doctrine mantém cada entidade carregada na memória durante toda a duração da requisição. Carregar grandes conjuntos de resultados causa crescimento ilimitado de memória. A solução alternativa — `$em->clear()` — desanexa tudo, incluindo entidades que você esqueceu de persistir novamente.

## O que o Weaver faz de diferente

O Weaver é construído sobre quatro princípios:

1. **Objetos PHP simples como entidades.** Sua classe `User` não tem dependências do ORM. Sem atributos, sem classe base, sem interface. É um objeto de valor puro ou objeto de domínio que pode ser testado unitariamente sem inicializar o Symfony.

2. **Classes de mapeamento explícitas.** Uma classe `UserMapper` separada descreve como `User` mapeia para a tabela `users`. Tipos de coluna, relações, chaves primárias — tudo em um só lugar, tudo em PHP puro, totalmente pesquisável e analisável estaticamente.

3. **Sem proxies, sem carregamento lazy implícito.** As relações são sempre carregadas explicitamente via `->with(['relation'])`. Você sempre sabe exatamente qual SQL é executado e quando.

4. **Seguro para workers por design.** Os mappers são sem estado e carregados uma vez por processo worker. Cada requisição HTTP ou job do Messenger recebe seu próprio `EntityWorkspace` (unidade de trabalho), portanto não há estado mutável compartilhado entre requisições.

## Principais diferenciais em resumo

| Funcionalidade | Doctrine ORM | Weaver ORM |
|---|---|---|
| Geração de classes proxy | Obrigatória | Desnecessária |
| Reflexão em tempo de execução | Sim | Nunca |
| Carregamento lazy | Implícito (proxy) | Apenas explícito |
| Anotações/atributos em entidades | Na classe de entidade | Classe de mapeamento separada |
| Reinicialização do processo worker | Sim | Não |
| Prevenção de N+1 | `JOIN FETCH` manual | Imposto por `with()` |
| Memória por 10k linhas | ~48 MB | ~11 MB |
| Tempo de hidratação para 10k linhas | ~420 ms | ~95 ms |
| PHPStan / análise estática | Parcial (proxies mágicos) | Completa (mappers explícitos) |

> Benchmarks: PHP 8.4, PostgreSQL 16, Ubuntu 22.04, 10.000 linhas de `User` com uma relação `Profile`. Os resultados variam conforme o hardware e a complexidade da consulta.

## Visão geral da arquitetura

```
Entity (classe PHP simples — zero acoplamento ao ORM)
    │
    └── Mapper (nome da tabela, colunas, relações, hydrate/extract)
            │
            └── EntityWorkspace → QueryBuilder → PDO/DBAL
```

O `EntityWorkspace` substitui o `EntityManager` do Doctrine. É uma unidade de trabalho com escopo de requisição que rastreia quais entidades precisam ser inseridas, atualizadas ou excluídas quando `flush()` é chamado. Por ter escopo de requisição, não há vazamento do mapa de identidade entre requisições.

## Suporte ao PyroSQL

O Weaver vem com suporte opcional ao **PyroSQL**, um mecanismo SQL analítico de alto desempenho em processo. O PyroSQL pode ser usado como réplica de leitura para consultas agregadas, relatórios e operações em grandes conjuntos de dados sem tocar no banco de dados relacional primário. Consulte a [seção PyroSQL](/pyrosql) para detalhes.

## Requisitos

| Dependência | Versão mínima |
|---|---|
| PHP | 8.4 |
| Symfony | 7.0 |
| doctrine/dbal | 4.0 (apenas camada de conexão) |
| MySQL | 8.0 |
| PostgreSQL | 14 |
| SQLite | 3.35 |

Opcional:

- `symfony/messenger` — publicação assíncrona de eventos e padrão outbox
- `symfony/cache` — cache de resultados de consultas
- `mongodb/mongodb` + `ext-mongodb` — suporte ao mapeador de documentos MongoDB

## O que o Weaver não é

O Weaver não é um substituto imediato do Doctrine. Se você depende fortemente do DQL, da API de critérios ou das migrações baseadas em atributos do Doctrine, precisará reescrever essa camada. O Weaver é mais indicado para **projetos Symfony 7+ greenfield** ou **aplicações sendo migradas do Doctrine** que desejam SQL explícito, previsível e persistência segura para workers.
