const accountField = document.getElementById('account-field');
if (accountField) {
  const role = document.getElementById('role');
  const username = document.getElementById('username');
  const updateAccount = () => {
    const shared = role.selectedOptions[0]?.dataset.shared === 'yes';
    accountField.hidden = !shared;
    username.disabled = !shared;
    username.required = shared;
  };
  role.addEventListener('change', updateAccount);
  updateAccount();
}

const reveal = document.getElementById('reveal');
const password = document.getElementById('password');
reveal.hidden = false;
reveal.addEventListener('click', () => {
  const show = password.type === 'password';
  password.type = show ? 'text' : 'password';
  reveal.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
});
