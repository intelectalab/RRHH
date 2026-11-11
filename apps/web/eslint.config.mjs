import { dirname } from "path";
import { fileURLToPath } from "url";
import { FlatCompat } from "@eslint/eslintrc";

const __filename = fileURLToPath(import.meta.url);
const __dirname = dirname(__filename);

const compat = new FlatCompat({
  baseDirectory: __dirname,
});

const eslintConfig = [
  ...compat.extends("next/core-web-vitals"),
  {
    // CFG-6 · Regla 12: ningún texto de interfaz escrito dentro de los componentes; todo sale de messages/{es,en,pt}.json.
    files: ["src/**/*.tsx"],
    ignores: ["src/test/**"],
    rules: {
      "react/jsx-no-literals": ["error", {
        noStrings: false,
        ignoreProps: true,
        allowedStrings: ["·", "—", "–", "/", "%", "×", "…", "+", "-", ":", "|", "(", ")", "#", "•", "→", "←", "*", "?"],
      }],
    },
  },
];

export default eslintConfig;
