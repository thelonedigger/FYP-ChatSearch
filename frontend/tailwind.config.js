/** @type {import('tailwindcss').Config} */
export default {
  darkMode: ["class"],
  content: [
    "./index.html",
    "./src/**/*.{js,ts,jsx,tsx}",
  ],
  theme: {
    extend: {
      colors: {
        border: "#E5E7EB",
        input: "#E5E7EB",
        ring: "#3B82F6",
        
        background: "#FFFFFF",
        foreground: "#111827",
        
        primary: {
          DEFAULT: "#3B82F6",
          foreground: "#FFFFFF",
          hover: "#2563EB",
        },
        
        secondary: {
          DEFAULT: "#F3F4F6",
          foreground: "#374151",
          hover: "#E5E7EB",
        },
        
        muted: {
          DEFAULT: "#F9FAFB",
          foreground: "#6B7280",
        },
        
        accent: {
          DEFAULT: "#F3F4F6",
          foreground: "#111827",
        },
        
        card: {
          DEFAULT: "#FFFFFF",
          foreground: "#111827",
        },
        
        sidebar: {
          DEFAULT: "#F9FAFB",
          foreground: "#111827",
          border: "#E5E7EB",
        },
      },
      
      borderRadius: {
        lg: "0.75rem",
        md: "0.5rem",
        sm: "0.25rem",
      },
      
      maxWidth: {
        'content': '42rem',
      },
      
      height: {
        'topbar': '4rem',
        'input-area': '5rem',
      },
      
      minHeight: {
        'topbar': '4rem',
      },
      
      spacing: {
        'sidebar': '16rem',
      },
    },
  },
  plugins: [
    require('@tailwindcss/typography'),
  ],
}