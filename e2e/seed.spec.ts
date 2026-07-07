import { test, expect } from '@playwright/test';
import mysql from 'mysql2/promise';

// Wzorcowy (seed) test E2E dla CVS.
// Ryzyko z test-plan.md (przykładowe): "niezweryfikowany/niezalogowany użytkownik
// nie powinien mieć dostępu do dashboardu; po weryfikacji i zalogowaniu sesja
// musi przetrwać odświeżenie strony". To ryzyko przechodzi przez wiele granic
// systemu (formularz -> CSRF -> baza -> bramka weryfikacji e-mail -> sesja),
// więc żaden test jednostkowy go nie pokryje.

// Bezpośrednie połączenie z lokalną bazą deweloperską — wyłącznie po to, by
// odczytać token weryfikacyjny i posprzątać testowego użytkownika. Domyślne
// wartości pasują do lokalnego XAMPP/MySQL (root, bez hasła); nadpisz przez
// zmienne środowiskowe, jeśli twoja konfiguracja jest inna.
async function dbConnection() {
  return mysql.createConnection({
    host: process.env.E2E_DB_HOST ?? '127.0.0.1',
    user: process.env.E2E_DB_USER ?? 'root',
    password: process.env.E2E_DB_PASSWORD ?? '',
    database: process.env.E2E_DB_NAME ?? 'cvs_dev',
  });
}

test('verified user reaches dashboard and session survives reload', async ({ page }) => {
  const email = `e2e-${Date.now()}@example.test`;
  const password = 'correct-horse-battery-staple';

  // --- Setup: rejestracja przez UI (prawdziwa granica: formularz + CSRF + baza) ---
  await page.goto('/register');
  await page.getByLabel('E-mail').fill(email);
  await page.getByLabel(/^Hasło/).fill(password);
  await page.getByLabel('Powtórz hasło').fill(password);
  await page.getByRole('button', { name: 'Utwórz konto' }).click();

  // Ryzyko #1: niezweryfikowane konto nie ląduje na dashboardzie.
  await expect(page).toHaveURL(/\/auth\/check-email/);

  // --- Weryfikacja e-mail: SMTP lokalnie nieskonfigurowany, więc czytamy
  // token bezpośrednio z bazy (to ten sam token, który poszedłby w mailu)
  // i wchodzimy pod prawdziwy link weryfikacyjny, zamiast omijać tę granicę. ---
  const db = await dbConnection();
  try {
    const [rows] = await db.execute(
      'SELECT email_verify_token FROM users WHERE email = ?',
      [email]
    );
    const token = (rows as any[])[0]?.email_verify_token;
    expect(token).toBeTruthy();

    // Kliknięcie linku weryfikacyjnego od razu loguje i ląduje na dashboardzie.
    await page.goto(`/auth/verify?token=${token}`);
    await expect(page).toHaveURL(/\/dashboard/);
    await expect(page.getByRole('heading', { name: 'Panel analizy CVS' })).toBeVisible();

    // --- Action: wylogowanie i ponowne logowanie przez UI (osobna ścieżka niż auto-login po weryfikacji) ---
    await page.goto('/logout');
    await expect(page).toHaveURL(/\/login/);

    await page.getByLabel('E-mail').fill(email);
    await page.getByLabel('Hasło').fill(password);
    await page.getByRole('button', { name: 'Zaloguj' }).click();

    // Ryzyko #2: zalogowany, zweryfikowany użytkownik widzi dashboard.
    await expect(page).toHaveURL(/\/dashboard/);
    await expect(page.getByRole('heading', { name: 'Panel analizy CVS' })).toBeVisible();

    // Ryzyko #3: sesja przetrwa odświeżenie strony (brak przekierowania na /login).
    await page.reload();
    await expect(page).toHaveURL(/\/dashboard/);
    await expect(page.getByRole('heading', { name: 'Panel analizy CVS' })).toBeVisible();
  } finally {
    // --- Cleanup: sprzątamy testowego użytkownika, żeby suite mógł się
    // uruchamiać wielokrotnie bez konfliktu unique(email). ---
    await db.execute('DELETE FROM users WHERE email = ?', [email]);
    await db.end();
  }
});
