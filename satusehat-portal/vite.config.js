import { defineConfig } from 'vite'
import react from '@vitejs/plugin-react'
import vitePluginBundleObfuscator from 'vite-plugin-bundle-obfuscator'
import fs from 'fs'
import path from 'path'
import { fileURLToPath } from 'url'
import JavaScriptObfuscator from 'javascript-obfuscator'

const __filename = fileURLToPath(import.meta.url)
const __dirname = path.dirname(__filename)

const obfuscatePublicConfig = () => {
  return {
    name: 'obfuscate-public-config',
    closeBundle() {
      const configPath = path.resolve(__dirname, 'dist/config.js')
      if (fs.existsSync(configPath)) {
        const rawCode = fs.readFileSync(configPath, 'utf8')
        const obfuscatedResult = JavaScriptObfuscator.obfuscate(rawCode, {
          compact: true,
          controlFlowFlattening: true,
          controlFlowFlatteningThreshold: 1.0,
          deadCodeInjection: true,
          deadCodeInjectionThreshold: 1.0,
          identifierNamesGenerator: 'hexadecimal',
          renameGlobals: false,
          selfDefending: true,
          stringArray: true,
          stringArrayEncoding: ['base64', 'rc4'],
          stringArrayThreshold: 1.0,
          splitStrings: true,
          splitStringsChunkLength: 3,
          unicodeEscapeSequence: true,
          numbersToExpressions: true,
          simplify: true
        })
        fs.writeFileSync(configPath, obfuscatedResult.getObfuscatedCode(), 'utf8')
        console.log('Successfully obfuscated config.js!')
      }
    }
  }
}

// https://vite.dev/config/
export default defineConfig({
  plugins: [
    react(),
    vitePluginBundleObfuscator({
      enable: true,
      log: true,
      options: {
        compact: true,
        controlFlowFlattening: true,
        controlFlowFlatteningThreshold: 1.0,
        deadCodeInjection: true,
        deadCodeInjectionThreshold: 1.0,
        identifierNamesGenerator: 'hexadecimal',
        renameGlobals: true,
        selfDefending: true,
        stringArray: true,
        stringArrayEncoding: ['base64', 'rc4'],
        stringArrayThreshold: 1.0,
        splitStrings: true,
        splitStringsChunkLength: 3,
        unicodeEscapeSequence: true,
        numbersToExpressions: true,
        simplify: true
      }
    }),
    obfuscatePublicConfig()
  ],
  base: './',
  build: {
    sourcemap: false,
    emptyOutDir: true,
    rollupOptions: {
      output: {
        entryFileNames: 'assets/[hash].js',
        chunkFileNames: 'assets/[hash].js',
        assetFileNames: 'assets/[hash].[ext]',
      }
    }
  }
})
