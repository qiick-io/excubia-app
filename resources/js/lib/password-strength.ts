type PasswordStrength = {
    progress: number;
    level: 'weak' | 'medium' | 'strong';
};

/** Estimates strength for the advisory meter shown while creating an account. */
export function evaluatePasswordStrength(value: string): PasswordStrength {
    const hasLower = /[a-z]/.test(value);
    const hasUpper = /[A-Z]/.test(value);
    const hasLetter = /[A-Za-z]/.test(value);
    const hasNumber = /\d/.test(value);
    const hasSymbol = /[^A-Za-z0-9]/.test(value);
    const hasMixedCase = hasLower && hasUpper;

    const level =
        value.length >= 12 &&
        hasLetter &&
        hasMixedCase &&
        hasNumber &&
        hasSymbol
            ? 'strong'
            : value.length >= 8 && hasMixedCase && hasNumber
              ? 'medium'
              : 'weak';

    const entropyChecks = [
        value.length >= 6,
        value.length >= 8,
        hasMixedCase,
        hasNumber,
        hasSymbol,
        value.length >= 12,
    ];
    const metEntropy = entropyChecks.filter(Boolean).length;
    const progress =
        value.length === 0
            ? 0
            : Math.round((metEntropy / entropyChecks.length) * 100);

    return { progress, level };
}
