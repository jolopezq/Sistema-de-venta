<script setup>
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { apiFetch } from '../services/api';
import { useThemeStore } from '../stores/theme';

/**
 * Recuperar la contraseña desde el login (docs/PLAN_RECUPERAR_CONTRASENA.md):
 * 1. correo → se envía un código de 6 dígitos, 2. código → permiso de un solo uso, 3. contraseña nueva.
 * El servidor responde lo mismo exista o no el correo; aquí tampoco se distingue.
 */
const route = useRoute();
const router = useRouter();
const theme = useThemeStore();

const step = ref(1);
const email = ref(typeof route.query.email === 'string' ? route.query.email : '');
const code = ref('');
const password = ref('');
const confirm = ref('');
const showPassword = ref(false);
const resetToken = ref('');

const loading = ref(false);
const info = ref('');
const banner = ref('');
const errors = ref({});

const resendIn = ref(0);
let timer = null;

const emailEl = ref(null);
const codeEl = ref(null);
const passwordEl = ref(null);

const subtitle = computed(() => ({
  1: 'Te enviamos un código de 6 dígitos a tu correo para elegir una contraseña nueva.',
  2: `Escribe el código que enviamos a ${email.value}.`,
  3: 'Elige tu contraseña nueva.',
}[step.value]));

function startCountdown(seconds) {
  clearInterval(timer);
  resendIn.value = seconds;
  timer = setInterval(() => {
    resendIn.value -= 1;
    if (resendIn.value <= 0) clearInterval(timer);
  }, 1000);
}
onBeforeUnmount(() => clearInterval(timer));

function focusStep() {
  nextTick(() => ({ 1: emailEl, 2: codeEl, 3: passwordEl }[step.value].value?.focus()));
}
onMounted(focusStep);

function fail(e, field) {
  if (e?.status === 429) {
    banner.value = e.message || 'Demasiados intentos. Espera unos minutos y vuelve a intentar.';
  } else if (e?.validationErrors) {
    errors.value = Object.fromEntries(Object.entries(e.validationErrors).map(([k, v]) => [k, v[0]]));
    if (errors.value.reset_token) {
      // El permiso venció: volver a pedir un código
      banner.value = errors.value.reset_token;
      step.value = 1;
      code.value = '';
      focusStep();
    }
  } else if (!e?.status) {
    banner.value = 'No hay conexión con el servidor. Revisa internet e intenta de nuevo.';
  } else {
    banner.value = e.message || 'No se pudo completar. Intenta de nuevo.';
  }
  if (field && errors.value[field]) focusStep();
}

function clearMessages() {
  banner.value = '';
  errors.value = {};
}

async function sendCode() {
  clearMessages();
  if (!email.value.trim()) { errors.value = { email: 'Escribe tu correo.' }; focusStep(); return; }
  loading.value = true;
  try {
    const res = await apiFetch('/password/code', { method: 'POST', body: JSON.stringify({ email: email.value.trim() }) });
    info.value = res.message;
    startCountdown(res.resend_after || 60);
    code.value = '';
    step.value = 2;
    focusStep();
  } catch (e) {
    fail(e, 'email');
  } finally {
    loading.value = false;
  }
}

async function resend() {
  if (resendIn.value > 0 || loading.value) return;
  await sendCode();
}

function onCodeInput(event) {
  // Solo dígitos (al pegar «123 456» o «Código: 123456» queda 123456)
  const digits = event.target.value.replace(/\D/g, '').slice(0, 6);
  // También en el campo: si el valor limpio no cambia, Vue no lo vuelve a pintar y la letra quedaría escrita
  event.target.value = digits;
  code.value = digits;
  if (errors.value.code) errors.value = { ...errors.value, code: undefined };
}

async function verifyCode() {
  clearMessages();
  if (!/^\d{6}$/.test(code.value)) { errors.value = { code: 'El código tiene 6 dígitos.' }; focusStep(); return; }
  loading.value = true;
  try {
    const res = await apiFetch('/password/verify', { method: 'POST', body: JSON.stringify({ email: email.value.trim(), code: code.value }) });
    resetToken.value = res.reset_token;
    info.value = '';
    step.value = 3;
    focusStep();
  } catch (e) {
    fail(e, 'code');
  } finally {
    loading.value = false;
  }
}

async function savePassword() {
  clearMessages();
  if (password.value.length < 6) { errors.value = { password: 'La contraseña nueva debe tener al menos 6 caracteres.' }; focusStep(); return; }
  if (password.value !== confirm.value) { errors.value = { password_confirmation: 'Las dos contraseñas no coinciden.' }; nextTick(() => document.getElementById('rp-confirm')?.focus()); return; }
  loading.value = true;
  try {
    await apiFetch('/password/reset', {
      method: 'POST',
      body: JSON.stringify({ email: email.value.trim(), reset_token: resetToken.value, password: password.value, password_confirmation: confirm.value }),
    });
    router.replace({ path: '/login', query: { email: email.value.trim(), restablecida: '1' } });
  } catch (e) {
    if (e?.validationErrors?.password_confirmation) e.validationErrors.password = e.validationErrors.password_confirmation;
    fail(e, 'password');
  } finally {
    loading.value = false;
  }
}

function changeEmail() {
  clearMessages();
  info.value = '';
  step.value = 1;
  focusStep();
}
</script>

<template>
  <div class="login-screen">
    <button type="button" class="login-theme-toggle" :aria-label="theme.isDark ? 'Cambiar a tema claro' : 'Cambiar a tema oscuro'" @click="theme.toggleTheme()">
      <svg v-if="theme.isDark" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="5" /><path d="M12 1v2M12 21v2M4.22 4.22l1.42 1.42M18.36 18.36l1.42 1.42M1 12h2M21 12h2M4.22 19.78l1.42-1.42M18.36 5.64l1.42-1.42" /></svg>
      <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" /></svg>
    </button>

    <div class="login-card rp-card">
      <div class="login-logo" aria-hidden="true"></div>
      <p class="rp-step">Paso {{ step }} de 3</p>
      <h1>Recuperar contraseña</h1>
      <p class="tagline rp-tagline">{{ subtitle }}</p>

      <div v-if="banner" class="error-banner rp-banner" role="alert">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="9" /><path d="M12 7v6M12 16.5v.5" /></svg>
        <span>{{ banner }}</span>
      </div>
      <p v-if="step === 2 && info" class="rp-info" role="status">{{ info }} Revisa también Spam o Promociones.</p>

      <!-- Paso 1: correo -->
      <form v-if="step === 1" novalidate @submit.prevent="sendCode">
        <div class="field">
          <label for="rp-email">Correo de tu cuenta</label>
          <input id="rp-email" ref="emailEl" v-model="email" type="email" autocomplete="email" inputmode="email" placeholder="tu@correo.com"
            :class="{ 'has-error': errors.email }" :aria-invalid="errors.email ? 'true' : null" :aria-describedby="errors.email ? 'rp-email-err' : null" />
          <span v-if="errors.email" id="rp-email-err" class="error-text">{{ errors.email }}</span>
        </div>
        <button type="submit" class="btn btn-primary" :disabled="loading">{{ loading ? 'Enviando…' : 'Enviar código' }}</button>
      </form>

      <!-- Paso 2: código -->
      <form v-else-if="step === 2" novalidate @submit.prevent="verifyCode">
        <div class="field">
          <label for="rp-code">Código de 6 dígitos</label>
          <input id="rp-code" ref="codeEl" :value="code" class="rp-code" type="text" inputmode="numeric" autocomplete="one-time-code" maxlength="6" placeholder="••••••"
            :class="{ 'has-error': errors.code }" :aria-invalid="errors.code ? 'true' : null" :aria-describedby="errors.code ? 'rp-code-err' : 'rp-code-help'" @input="onCodeInput" />
          <span v-if="errors.code" id="rp-code-err" class="error-text">{{ errors.code }}</span>
          <span v-else id="rp-code-help" class="rp-help">Sirve una sola vez. Si pides otro, el anterior deja de servir.</span>
        </div>
        <button type="submit" class="btn btn-primary" :disabled="loading">{{ loading ? 'Verificando…' : 'Verificar código' }}</button>
        <div class="rp-links">
          <button type="button" class="rp-link" :disabled="resendIn > 0 || loading" @click="resend">
            {{ resendIn > 0 ? `Reenviar código en ${resendIn} s` : 'Reenviar código' }}
          </button>
          <button type="button" class="rp-link" :disabled="loading" @click="changeEmail">Usar otro correo</button>
        </div>
      </form>

      <!-- Paso 3: contraseña nueva -->
      <form v-else novalidate @submit.prevent="savePassword">
        <div class="field">
          <label for="rp-password">Contraseña nueva</label>
          <div class="rp-pass">
            <input id="rp-password" ref="passwordEl" v-model="password" :type="showPassword ? 'text' : 'password'" autocomplete="new-password" minlength="6"
              :class="{ 'has-error': errors.password }" :aria-invalid="errors.password ? 'true' : null" :aria-describedby="errors.password ? 'rp-password-err' : 'rp-password-help'" />
            <button type="button" class="rp-eye" :aria-label="showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'" :aria-pressed="showPassword ? 'true' : 'false'" @click="showPassword = !showPassword">
              <svg v-if="showPassword" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19M1 1l22 22" /><path d="M14.12 14.12a3 3 0 1 1-4.24-4.24" /></svg>
              <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z" /><circle cx="12" cy="12" r="3" /></svg>
            </button>
          </div>
          <span v-if="errors.password" id="rp-password-err" class="error-text">{{ errors.password }}</span>
          <span v-else id="rp-password-help" class="rp-help">Al menos 6 caracteres. Se cerrarán las sesiones abiertas con la contraseña anterior.</span>
        </div>
        <div class="field">
          <label for="rp-confirm">Repite la contraseña nueva</label>
          <input id="rp-confirm" v-model="confirm" :type="showPassword ? 'text' : 'password'" autocomplete="new-password"
            :class="{ 'has-error': errors.password_confirmation }" :aria-invalid="errors.password_confirmation ? 'true' : null"
            :aria-describedby="errors.password_confirmation ? 'rp-confirm-err' : null" />
          <span v-if="errors.password_confirmation" id="rp-confirm-err" class="error-text">{{ errors.password_confirmation }}</span>
        </div>
        <button type="submit" class="btn btn-primary" :disabled="loading">{{ loading ? 'Guardando…' : 'Guardar contraseña' }}</button>
      </form>

      <router-link class="rp-back" :to="{ path: '/login', query: email ? { email } : {} }">Volver a iniciar sesión</router-link>
    </div>
  </div>
</template>

<style scoped>
.login-screen {
  min-height: 100vh;
  position: relative;
  background:
    radial-gradient(circle at 15% 20%, rgba(140,63,136,0.55) 0, transparent 45%),
    radial-gradient(circle at 85% 80%, rgba(255,107,69,0.35) 0, transparent 45%),
    var(--acai-900);
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 40px 16px;
}
.rp-card { max-width: 100%; }
.rp-step { margin: 0 0 4px; font-size: 12px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; color: var(--acai-text); }
.rp-tagline { overflow-wrap: anywhere; }
.rp-banner { margin: 0 0 16px; }
.rp-banner svg { width: 18px; height: 18px; flex-shrink: 0; }
.rp-info { margin: 0 0 16px; padding: 10px 12px; border-radius: 12px; background: var(--lime-100); color: var(--lime-700); font-size: 13px; font-weight: 600; line-height: 1.4; }
.rp-help { display: block; margin-top: 6px; font-size: 12px; color: var(--ink-500); line-height: 1.4; }
.rp-code { font-family: 'Baloo 2', 'Inter', sans-serif; font-size: 26px !important; font-weight: 700; letter-spacing: .4em; text-align: center; }
.rp-pass { position: relative; }
.rp-pass input { padding-right: 48px; }
.rp-eye {
  position: absolute; top: 50%; right: 6px; transform: translateY(-50%);
  width: 38px; height: 38px; border: 0; border-radius: 10px; background: transparent; color: var(--ink-500);
  display: flex; align-items: center; justify-content: center; cursor: pointer;
}
.rp-eye:hover { background: var(--cream-200); color: var(--ink-900); }
.rp-eye:focus-visible, .rp-link:focus-visible, .rp-back:focus-visible { outline: 3px solid var(--acai-500); outline-offset: 2px; }
.rp-eye svg { width: 20px; height: 20px; }
.rp-links { display: flex; justify-content: space-between; gap: 12px; margin-top: 14px; flex-wrap: wrap; }
.rp-link { border: 0; background: none; padding: 4px 0; font: inherit; font-size: 13.5px; font-weight: 600; color: var(--acai-text); cursor: pointer; text-decoration: underline; text-underline-offset: 3px; }
.rp-link:disabled { color: var(--ink-500); text-decoration: none; cursor: default; }
.rp-back { display: block; margin-top: 18px; text-align: center; font-size: 13.5px; font-weight: 600; color: var(--acai-text); text-decoration: underline; text-underline-offset: 3px; }
.has-error { border-color: var(--danger-500) !important; background-color: var(--danger-100) !important; }
.error-text { color: var(--danger-text); font-size: 12px; font-weight: 600; margin-top: 6px; display: block; }

/* Portátil de caja al 125 % (≈ 489 px de alto): menos aire para que el botón quede a la vista */
@media (max-height: 560px) {
  .login-screen { padding: 12px 16px; align-items: flex-start; }
  .rp-card { padding: 18px 24px; }
  .rp-card .login-logo { display: none; }
  .rp-card h1 { font-size: 21px; }
  .rp-tagline { margin-bottom: 14px !important; }
  .rp-card .field { margin-bottom: 12px; }
}
</style>
