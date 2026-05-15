#!/usr/bin/env node

import fs from 'node:fs';
import http from 'node:http';
import https from 'node:https';
import path from 'node:path';

const config = {
    baseUrl: process.env.KMP_BASE_URL || 'http://127.0.0.1:8080',
    outputDir: process.env.KMP_PERF_OUTPUT_DIR || '/workspaces/KMP/test-results/perf',
    path: process.env.KMP_MT_PATH || '/members/login',
    timeoutMs: Math.max(1000, Number.parseInt(process.env.KMP_MT_TIMEOUT_MS || '10000', 10)),
    poolSize: Math.max(1, Number.parseInt(process.env.KMP_MT_POOL_SIZE || '8', 10)),
    requestsPerWorker: Math.max(1, Number.parseInt(process.env.KMP_MT_REQUESTS_PER_WORKER || '50', 10)),
    warmupRequests: Math.max(0, Number.parseInt(process.env.KMP_MT_WARMUP_REQUESTS || '2', 10)),
    tenantMatrix: process.env.KMP_MULTI_TENANTS || 'tenant-a@tenant-a.local@4;tenant-b@tenant-b.local@4',
    thresholds: {
        p95ResolutionMs: Math.max(50, Number.parseInt(process.env.KMP_MT_P95_THRESHOLD_MS || '800', 10)),
        failurePct: Math.max(0, Number.parseFloat(process.env.KMP_MT_FAILURE_THRESHOLD_PCT || '1')),
        minThroughputRps: Math.max(0.1, Number.parseFloat(process.env.KMP_MT_MIN_THROUGHPUT_RPS || '5')),
        queueP95Ms: Math.max(0, Number.parseInt(process.env.KMP_MT_QUEUE_P95_THRESHOLD_MS || '250', 10)),
        noisyNeighborP95DegradationPct: Math.max(0, Number.parseFloat(process.env.KMP_MT_NOISY_NEIGHBOR_THRESHOLD_PCT || '40')),
    },
};

function nowMs() {
    return Number(process.hrtime.bigint()) / 1_000_000;
}

function round(value) {
    if (value === null || value === undefined || Number.isNaN(value)) {
        return null;
    }

    return Number.parseFloat(value.toFixed(2));
}

function percentile(values, p) {
    if (!values.length) {
        return null;
    }
    const sorted = [...values].sort((a, b) => a - b);
    const rank = Math.max(0, Math.ceil((p / 100) * sorted.length) - 1);
    return sorted[rank];
}

function summarizeNumbers(values) {
    if (!values.length) {
        return { min: null, avg: null, p50: null, p95: null, max: null };
    }

    const total = values.reduce((sum, value) => sum + value, 0);
    return {
        min: round(Math.min(...values)),
        avg: round(total / values.length),
        p50: round(percentile(values, 50)),
        p95: round(percentile(values, 95)),
        max: round(Math.max(...values)),
    };
}

function parseTenantMatrix(value) {
    return value
        .split(';')
        .map((entry) => entry.trim())
        .filter(Boolean)
        .map((entry, index) => {
            const [nameRaw, hostnameRaw, workersRaw] = entry.split('@').map((part) => part.trim());
            const name = nameRaw || `tenant-${index + 1}`;
            const hostname = hostnameRaw || `${name}.local`;
            const workers = Math.max(1, Number.parseInt(workersRaw || '1', 10));

            return { name, hostname, workers };
        });
}

class RequestPool {
    constructor(limit) {
        this.limit = limit;
        this.active = 0;
        this.waiters = [];
        this.maxInFlight = 0;
        this.saturationStarts = 0;
    }

    async run(task) {
        const queuedAt = nowMs();
        if (this.active >= this.limit) {
            await new Promise((resolve) => this.waiters.push(resolve));
        }
        const startedAt = nowMs();
        const queueWaitMs = startedAt - queuedAt;

        this.active += 1;
        this.maxInFlight = Math.max(this.maxInFlight, this.active);
        if (this.active === this.limit) {
            this.saturationStarts += 1;
        }

        try {
            return await task(round(queueWaitMs));
        } finally {
            this.active -= 1;
            const waiter = this.waiters.shift();
            if (waiter) {
                waiter();
            }
        }
    }
}

async function executeRequest(tenant) {
    const url = new URL(config.path, config.baseUrl);
    const startedAt = nowMs();
    const transport = url.protocol === 'https:' ? https : http;

    const { statusCode, responseCompletedAt, responseHeadersAt } = await new Promise((resolve, reject) => {
        const req = transport.request({
            protocol: url.protocol,
            hostname: url.hostname,
            port: url.port,
            method: 'GET',
            path: `${url.pathname}${url.search}`,
            timeout: config.timeoutMs,
            headers: {
                Host: tenant.hostname,
                'x-kmp-tenant': tenant.name,
                'x-kmp-load-test': 'multi-tenant',
            },
        }, (res) => {
            const responseHeadersAt = nowMs();
            res.resume();
            res.on('end', () => {
                resolve({
                    statusCode: res.statusCode ?? 0,
                    responseCompletedAt: nowMs(),
                    responseHeadersAt,
                });
            });
        });

        req.on('timeout', () => {
            req.destroy(new Error(`Request timeout after ${config.timeoutMs}ms`));
        });
        req.on('error', (error) => reject(error));
        req.end();
    });
    return {
        statusCode,
        resolutionLatencyMs: round(responseHeadersAt - startedAt),
        totalLatencyMs: round(responseCompletedAt - startedAt),
    };
}

async function warmupTenants(tenants) {
    for (const tenant of tenants) {
        for (let i = 0; i < config.warmupRequests; i += 1) {
            await executeRequest(tenant);
        }
    }
}

function buildTaskPlan(tenants) {
    const tasks = [];
    for (const tenant of tenants) {
        for (let worker = 1; worker <= tenant.workers; worker += 1) {
            for (let iteration = 1; iteration <= config.requestsPerWorker; iteration += 1) {
                tasks.push({
                    tenant,
                    worker,
                    iteration,
                });
            }
        }
    }
    return tasks;
}

async function runScenario({ name, tenants, poolSize }) {
    const pool = new RequestPool(poolSize);
    const taskPlan = buildTaskPlan(tenants);
    const startedAt = nowMs();
    const samples = await Promise.all(taskPlan.map((task) => pool.run(async (queueWaitMs) => {
        const requestStartedAt = nowMs();
        try {
            const result = await executeRequest(task.tenant);
            return {
                scenario: name,
                tenant: task.tenant.name,
                hostname: task.tenant.hostname,
                worker: task.worker,
                iteration: task.iteration,
                queueWaitMs,
                resolutionLatencyMs: result.resolutionLatencyMs,
                totalLatencyMs: result.totalLatencyMs,
                statusCode: result.statusCode,
                ok: result.statusCode >= 200 && result.statusCode < 400,
                error: null,
                requestRuntimeMs: round(nowMs() - requestStartedAt),
            };
        } catch (error) {
            return {
                scenario: name,
                tenant: task.tenant.name,
                hostname: task.tenant.hostname,
                worker: task.worker,
                iteration: task.iteration,
                queueWaitMs,
                resolutionLatencyMs: null,
                totalLatencyMs: null,
                statusCode: 0,
                ok: false,
                error: error.message,
                requestRuntimeMs: round(nowMs() - requestStartedAt),
            };
        }
    })));

    const durationMs = round(nowMs() - startedAt);
    const requestCount = samples.length;
    const failures = samples.filter((sample) => !sample.ok).length;
    const throughputRps = durationMs > 0 ? round(requestCount / (durationMs / 1000)) : null;

    const queueWaitSummary = summarizeNumbers(samples.map((sample) => sample.queueWaitMs).filter((value) => value !== null));
    const resolutionSummary = summarizeNumbers(samples.map((sample) => sample.resolutionLatencyMs).filter((value) => value !== null));
    const totalLatencySummary = summarizeNumbers(samples.map((sample) => sample.totalLatencyMs).filter((value) => value !== null));
    const perTenant = summarizePerTenant(samples);

    return {
        name,
        poolSize,
        durationMs,
        requestCount,
        failures,
        failurePct: requestCount > 0 ? round((failures / requestCount) * 100) : 0,
        throughputRps,
        queue: {
            ...queueWaitSummary,
            saturationStarts: pool.saturationStarts,
            maxInFlight: pool.maxInFlight,
        },
        resolutionLatencyMs: resolutionSummary,
        totalLatencyMs: totalLatencySummary,
        perTenant,
        samples,
    };
}

function summarizePerTenant(samples) {
    const grouped = new Map();
    for (const sample of samples) {
        if (!grouped.has(sample.tenant)) {
            grouped.set(sample.tenant, []);
        }
        grouped.get(sample.tenant).push(sample);
    }

    return Array.from(grouped.entries()).map(([tenant, tenantSamples]) => {
        const requestCount = tenantSamples.length;
        const failures = tenantSamples.filter((sample) => !sample.ok).length;
        return {
            tenant,
            requestCount,
            failures,
            failurePct: requestCount > 0 ? round((failures / requestCount) * 100) : 0,
            resolutionLatencyMs: summarizeNumbers(
                tenantSamples.map((sample) => sample.resolutionLatencyMs).filter((value) => value !== null)
            ),
            totalLatencyMs: summarizeNumbers(
                tenantSamples.map((sample) => sample.totalLatencyMs).filter((value) => value !== null)
            ),
            queueWaitMs: summarizeNumbers(
                tenantSamples.map((sample) => sample.queueWaitMs).filter((value) => value !== null)
            ),
        };
    });
}

function buildNoisyNeighborSummary(baselineScenarios, mixedScenario) {
    const mixedByTenant = new Map(mixedScenario.perTenant.map((entry) => [entry.tenant, entry]));
    return baselineScenarios.map((baseline) => {
        const baselineTenant = baseline.perTenant.find((entry) => entry.tenant === baseline.name.replace('baseline:', ''));
        const mixedTenant = mixedByTenant.get(baselineTenant?.tenant || '');
        const baselineP95 = baselineTenant?.resolutionLatencyMs?.p95 ?? null;
        const mixedP95 = mixedTenant?.resolutionLatencyMs?.p95 ?? null;
        const degradationPct =
            baselineP95 && mixedP95
                ? round(((mixedP95 - baselineP95) / baselineP95) * 100)
                : null;

        return {
            tenant: baselineTenant?.tenant || baseline.name,
            baselineResolutionP95Ms: baselineP95,
            mixedResolutionP95Ms: mixedP95,
            degradationPct,
        };
    });
}

function evaluateThresholds(mixedScenario, noisyNeighbor, tenants) {
    const failures = [];
    const warnings = [];

    if (
        mixedScenario.resolutionLatencyMs.p95 !== null &&
        mixedScenario.resolutionLatencyMs.p95 > config.thresholds.p95ResolutionMs
    ) {
        failures.push(
            `Resolution latency p95 ${mixedScenario.resolutionLatencyMs.p95}ms exceeds ${config.thresholds.p95ResolutionMs}ms.`
        );
    }

    if (mixedScenario.failurePct > config.thresholds.failurePct) {
        failures.push(`Failure rate ${mixedScenario.failurePct}% exceeds ${config.thresholds.failurePct}%.`);
    }

    if (
        mixedScenario.throughputRps !== null &&
        mixedScenario.throughputRps < config.thresholds.minThroughputRps
    ) {
        failures.push(
            `Throughput ${mixedScenario.throughputRps} req/s is below ${config.thresholds.minThroughputRps} req/s.`
        );
    }

    if (mixedScenario.queue.p95 !== null && mixedScenario.queue.p95 > config.thresholds.queueP95Ms) {
        warnings.push(`Queue wait p95 ${mixedScenario.queue.p95}ms exceeds ${config.thresholds.queueP95Ms}ms.`);
    }

    const noisyNeighbors = noisyNeighbor.filter(
        (entry) =>
            entry.degradationPct !== null &&
            entry.degradationPct > config.thresholds.noisyNeighborP95DegradationPct
    );
    for (const entry of noisyNeighbors) {
        warnings.push(
            `Noisy-neighbor: ${entry.tenant} resolution p95 degraded ${entry.degradationPct}% (threshold ${config.thresholds.noisyNeighborP95DegradationPct}%).`
        );
    }

    return {
        tenantCount: tenants.length,
        pass: failures.length === 0,
        failures,
        warnings,
    };
}

function printSummary(report, reportFile) {
    const mixed = report.mixedScenario;
    console.log('\nMulti-tenant load summary:');
    console.log(`Tenants: ${report.tenants.length} | pool size: ${mixed.poolSize} | requests: ${mixed.requestCount}`);
    console.log(
        `Resolution p95: ${mixed.resolutionLatencyMs.p95}ms | failures: ${mixed.failurePct}% | throughput: ${mixed.throughputRps} req/s`
    );
    console.log(
        `Queue p95: ${mixed.queue.p95}ms | saturation events: ${mixed.queue.saturationStarts} | max in-flight: ${mixed.queue.maxInFlight}`
    );

    console.log('\nPer-tenant (mixed):');
    for (const tenant of mixed.perTenant) {
        console.log(
            `${tenant.tenant} | req ${tenant.requestCount} | p95 ${tenant.resolutionLatencyMs.p95}ms | fail ${tenant.failurePct}%`
        );
    }

    console.log('\nNoisy-neighbor check:');
    for (const entry of report.noisyNeighbor) {
        console.log(
            `${entry.tenant} | baseline p95 ${entry.baselineResolutionP95Ms}ms -> mixed p95 ${entry.mixedResolutionP95Ms}ms | degradation ${entry.degradationPct}%`
        );
    }

    const verdict = report.evaluation.pass ? 'PASS' : 'FAIL';
    console.log(`\nThreshold verdict: ${verdict}`);
    for (const message of report.evaluation.failures) {
        console.log(`FAIL: ${message}`);
    }
    for (const message of report.evaluation.warnings) {
        console.log(`WARN: ${message}`);
    }
    console.log(`Report written: ${reportFile}`);
}

async function main() {
    const tenants = parseTenantMatrix(config.tenantMatrix);
    if (tenants.length < 2) {
        throw new Error('KMP_MULTI_TENANTS must include at least two tenants for multi-tenant validation.');
    }

    fs.mkdirSync(config.outputDir, { recursive: true });
    await warmupTenants(tenants);

    const baselineScenarios = [];
    for (const tenant of tenants) {
        baselineScenarios.push(
            await runScenario({
                name: `baseline:${tenant.name}`,
                tenants: [{ ...tenant, workers: 1 }],
                poolSize: 1,
            })
        );
    }

    const mixedScenario = await runScenario({
        name: 'multi-tenant-mixed',
        tenants,
        poolSize: config.poolSize,
    });

    const noisyNeighbor = buildNoisyNeighborSummary(baselineScenarios, mixedScenario);
    const evaluation = evaluateThresholds(mixedScenario, noisyNeighbor, tenants);

    const report = {
        generatedAt: new Date().toISOString(),
        config,
        tenants,
        baselineScenarios: baselineScenarios.map((scenario) => ({
            ...scenario,
            samples: undefined,
        })),
        mixedScenario,
        noisyNeighbor,
        evaluation,
    };

    const timestamp = new Date().toISOString().replace(/[:.]/g, '-');
    const reportFile = path.join(config.outputDir, `multi-tenant-report-${timestamp}.json`);
    fs.writeFileSync(reportFile, `${JSON.stringify(report, null, 2)}\n`, 'utf8');
    printSummary(report, reportFile);
}

main().catch((error) => {
    console.error(`Multi-tenant load test failed: ${error.message}`);
    process.exit(1);
});
