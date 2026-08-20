/**
 * ZeroBoiler Analytics — useEventSequence Svelte Composable
 *
 * Reactive composable for fetching and consuming event sequence
 * value attribution data from the ZeroBoiler analytics API.
 *
 * Provides reactive state for the attribution matrix, top sequences,
 * grade distribution, and comparison results.
 *
 * @since 213.0.0
 * @version 272.0.0
 */

/**
 * @typedef {Object} SequenceAttribution
 * @property {string} sequence_id
 * @property {string[]} sequence
 * @property {number} composite_score
 * @property {string} value_grade
 * @property {number} occurrences
 * @property {number} avg_ltv
 * @property {number} conversion_lift
 * @property {number} sequence_roi
 */

/**
 * @typedef {Object} AttributionMatrix
 * @property {SequenceAttribution[]} attributions
 * @property {{ total_sequences: number, top_path: string|null, avg_score: number, grade_distribution: { S: number, A: number, B: number, C: number, D: number }, highest_ltv_path: string|null, fastest_path: string|null }} summary
 */

/**
 * @typedef {Object} SequenceComparison
 * @property {{ score: number, grade: string, ltv: number, roi: number }} sequence_a
 * @property {{ score: number, grade: string, ltv: number, roi: number }} sequence_b
 * @property {number} delta
 * @property {string} recommendation
 */

/**
 * @typedef {Object} UseEventSequenceReturn
 * @property {AttributionMatrix | null} matrix
 * @property {boolean} loading
 * @property {string | null} error
 * @property {() => Promise<void>} fetchMatrix
 * @property {(n?: number) => SequenceAttribution[]} topSequences
 * @property {(grade?: string) => SequenceAttribution[]} byGrade
 * @property {() => { S: number, A: number, B: number, C: number, D: number }} gradeDistribution
 * @property {(seqA: string[], seqB: string[]) => Promise<SequenceComparison>} compare
 * @property {() => number} avgScore
 * @property {() => string | null} topPath
 */

/**
 * Create a reactive event sequence value attribution composable.
 *
 * @param {string} [baseUrl='/api/analytics'] API base URL
 * @returns {UseEventSequenceReturn}
 */
export function useEventSequence(baseUrl = '/api/analytics') {
  /** @type {import('svelte/store').Writable<AttributionMatrix | null>} */
  let matrix = $state(null);
  let loading = $state(false);
  let error = $state(null);

  /**
   * Fetch the full attribution matrix from the API.
   */
  async function fetchMatrix() {
    loading = true;
    error = null;

    try {
      const response = await fetch(`${baseUrl}/sequence-value/matrix`);

      if (!response.ok) {
        throw new Error(`Failed to fetch sequence value matrix: ${response.status}`);
      }

      const data = await response.json();
      matrix = data;
    } catch (err) {
      error = err instanceof Error ? err.message : 'Unknown error';
      matrix = null;
    } finally {
      loading = false;
    }
  }

  /**
   * Get the top N sequences by composite score.
   *
   * @param {number} [n=5] Number of top sequences
   * @returns {SequenceAttribution[]}
   */
  function topSequences(n = 5) {
    if (!matrix?.attributions) return [];
    return matrix.attributions.slice(0, n);
  }

  /**
   * Filter sequences by value grade.
   *
   * @param {string} [grade] Grade to filter by (S, A, B, C, D). If omitted, returns all.
   * @returns {SequenceAttribution[]}
   */
  function byGrade(grade) {
    if (!matrix?.attributions) return [];
    if (!grade) return matrix.attributions;
    return matrix.attributions.filter((a) => a.value_grade === grade);
  }

  /**
   * Get the grade distribution summary.
   *
   * @returns {{ S: number, A: number, B: number, C: number, D: number }}
   */
  function gradeDistribution() {
    return matrix?.summary?.grade_distribution ?? { S: 0, A: 0, B: 0, C: 0, D: 0 };
  }

  /**
   * Compare two event sequences by value.
   *
   * @param {string[]} seqA First sequence of event names
   * @param {string[]} seqB Second sequence of event names
   * @returns {Promise<SequenceComparison>}
   */
  async function compare(seqA, seqB) {
    const seqAStr = seqA.join('→');
    const seqBStr = seqB.join('→');

    const response = await fetch(
      `${baseUrl}/sequence-value/compare?seq_a=${encodeURIComponent(seqAStr)}&seq_b=${encodeURIComponent(seqBStr)}`
    );

    if (!response.ok) {
      throw new Error(`Failed to compare sequences: ${response.status}`);
    }

    return await response.json();
  }

  /**
   * Get the average composite score.
   *
   * @returns {number}
   */
  function avgScore() {
    return matrix?.summary?.avg_score ?? 0;
  }

  /**
   * Get the top path string.
   *
   * @returns {string | null}
   */
  function topPath() {
    return matrix?.summary?.top_path ?? null;
  }

  return {
    get matrix() {
      return matrix;
    },
    get loading() {
      return loading;
    },
    get error() {
      return error;
    },
    fetchMatrix,
    topSequences,
    byGrade,
    gradeDistribution,
    compare,
    avgScore,
    topPath,
  };
}

export default useEventSequence;
