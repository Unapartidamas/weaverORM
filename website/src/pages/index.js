import React from 'react';
import Link from '@docusaurus/Link';
import useDocusaurusContext from '@docusaurus/useDocusaurusContext';
import Layout from '@theme/Layout';

const features = [
  {
    title: 'Zero Reflection',
    icon: '⚡',
    description:
      'No runtime reflection, no magic proxies, no hidden overhead. Weaver uses PHP 8.4 property hooks and compile-time metadata — your entities are plain PHP objects.',
  },
  {
    title: 'Worker-Safe',
    icon: '🔒',
    description:
      'Each request gets its own EntityWorkspace with isolated identity maps and change tracking. No shared state, no cross-request contamination — built for PHP workers and async environments.',
  },
  {
    title: 'PyroSQL Ready',
    icon: '🚀',
    description:
      'First-class support for PyroSQL\'s advanced features: time travel queries, database branching, change data capture (CDC), approximate aggregates, and vector similarity search.',
  },
];

function HeroSection() {
  return (
    <div
      style={{
        background: 'linear-gradient(135deg, #4c1d95 0%, #7c3aed 50%, #a78bfa 100%)',
        color: '#fff',
        padding: '80px 20px',
        textAlign: 'center',
      }}
    >
      <div style={{ maxWidth: 800, margin: '0 auto' }}>
        <h1
          style={{
            fontSize: '3.5rem',
            fontWeight: 800,
            margin: '0 0 16px',
            letterSpacing: '-0.02em',
          }}
        >
          Weaver ORM
        </h1>
        <p
          style={{
            fontSize: '1.35rem',
            opacity: 0.9,
            margin: '0 0 40px',
            lineHeight: 1.6,
          }}
        >
          PHP 8.4+ ORM for Symfony.
          <br />
          Zero reflection, no proxies, no memory leaks.
        </p>
        <div style={{ display: 'flex', gap: 16, justifyContent: 'center', flexWrap: 'wrap' }}>
          <Link
            to="/docs/intro"
            style={{
              background: '#fff',
              color: '#7c3aed',
              padding: '14px 32px',
              borderRadius: 8,
              fontWeight: 700,
              fontSize: '1rem',
              textDecoration: 'none',
              border: '2px solid #fff',
              transition: 'all 0.2s',
            }}
          >
            Get Started
          </Link>
          <a
            href="https://github.com/weaver-orm/weaver"
            target="_blank"
            rel="noopener noreferrer"
            style={{
              background: 'transparent',
              color: '#fff',
              padding: '14px 32px',
              borderRadius: 8,
              fontWeight: 700,
              fontSize: '1rem',
              textDecoration: 'none',
              border: '2px solid rgba(255,255,255,0.6)',
              transition: 'all 0.2s',
            }}
          >
            View on GitHub
          </a>
        </div>
      </div>
    </div>
  );
}

function FeatureCard({ title, icon, description }) {
  return (
    <div
      style={{
        flex: '1 1 280px',
        background: 'var(--ifm-card-background-color)',
        border: '1px solid var(--ifm-color-emphasis-200)',
        borderRadius: 12,
        padding: '32px 28px',
        boxShadow: '0 2px 8px rgba(0,0,0,0.06)',
      }}
    >
      <div style={{ fontSize: '2.5rem', marginBottom: 16 }}>{icon}</div>
      <h3 style={{ margin: '0 0 12px', fontSize: '1.25rem', fontWeight: 700 }}>{title}</h3>
      <p style={{ margin: 0, lineHeight: 1.7, color: 'var(--ifm-color-emphasis-700)' }}>
        {description}
      </p>
    </div>
  );
}

function FeaturesSection() {
  return (
    <div
      style={{
        padding: '64px 20px',
        background: 'var(--ifm-background-surface-color)',
      }}
    >
      <div style={{ maxWidth: 1100, margin: '0 auto' }}>
        <h2
          style={{
            textAlign: 'center',
            fontSize: '2rem',
            fontWeight: 700,
            marginBottom: 48,
            color: 'var(--ifm-color-emphasis-900)',
          }}
        >
          Why Weaver?
        </h2>
        <div
          style={{
            display: 'flex',
            gap: 24,
            flexWrap: 'wrap',
            justifyContent: 'center',
          }}
        >
          {features.map((feature) => (
            <FeatureCard key={feature.title} {...feature} />
          ))}
        </div>
      </div>
    </div>
  );
}

function CodePreviewSection() {
  return (
    <div style={{ padding: '64px 20px', background: 'var(--ifm-background-color)' }}>
      <div style={{ maxWidth: 860, margin: '0 auto', textAlign: 'center' }}>
        <h2 style={{ fontSize: '2rem', fontWeight: 700, marginBottom: 16 }}>
          Clean, Expressive Mapping
        </h2>
        <p style={{ color: 'var(--ifm-color-emphasis-700)', marginBottom: 40, fontSize: '1.05rem' }}>
          Define entities with PHP 8.4 attributes and property hooks. No XML, no YAML, no annotations magic.
        </p>
        <div
          style={{
            background: '#1e1e2e',
            borderRadius: 12,
            padding: '32px',
            textAlign: 'left',
            overflowX: 'auto',
            boxShadow: '0 8px 32px rgba(0,0,0,0.18)',
          }}
        >
          <pre style={{ margin: 0, color: '#cdd6f4', fontSize: '0.9rem', lineHeight: 1.7 }}>
            <code>{`#[Entity]
class Order
{
    #[Id, GeneratedValue]
    public int $id;

    #[Column]
    public OrderStatus $status = OrderStatus::Draft;

    #[OneToMany(targetEntity: OrderLine::class)]
    public Collection $lines;

    #[Embedded]
    public Money $total;
}

// In your service
$order = $workspace->find(Order::class, $id);
$order->status = OrderStatus::Confirmed;
$workspace->flush(); // only changed fields are updated`}</code>
          </pre>
        </div>
      </div>
    </div>
  );
}

export default function Home() {
  const { siteConfig } = useDocusaurusContext();
  return (
    <Layout title={siteConfig.title} description={siteConfig.tagline}>
      <main>
        <HeroSection />
        <FeaturesSection />
        <CodePreviewSection />
      </main>
    </Layout>
  );
}
