<template>
  <div class="min-h-screen flex items-center justify-center bg-gradient-to-br from-slate-100 to-slate-300 dark:from-slate-900 dark:to-slate-800 p-8">
    <div class="w-full max-w-[1200px] mx-auto">
      <div class="bg-white dark:bg-slate-800 rounded-lg shadow-md overflow-hidden max-w-sm mx-auto">
        <div class="px-8 py-8 text-center bg-blue-500 text-white">
          <h1 class="text-[1.8rem] font-bold leading-tight">Tradinet Solutions</h1>
          <p class="mt-2 text-sm opacity-90">Wifi Hotspot Management System</p>
        </div>
        <slot></slot>
      </div>
    </div>
  </div>
</template>

<script setup></script>
