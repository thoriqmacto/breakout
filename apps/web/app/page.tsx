import Link from "next/link"

import { buttonVariants } from "@/components/ui/button"

export default function Home() {
  return (
    <div className="flex min-h-screen flex-col items-center justify-center gap-10 bg-muted/30 px-6 py-16 text-center">
      <div className="space-y-4">
        <span className="inline-flex items-center rounded-full bg-primary/10 px-3 py-1 text-xs font-medium uppercase tracking-wide text-primary">
          Breakout Platform
        </span>
        <h1 className="text-balance text-4xl font-semibold sm:text-5xl">
          Monitor and manage your trading insights in one place
        </h1>
        <p className="mx-auto max-w-2xl text-balance text-base text-muted-foreground sm:text-lg">
          Sign in to access the operational dashboard, review market performance, and stay aligned with the latest
          alerts from the Breakout API.
        </p>
      </div>
      <div className="flex flex-col gap-3 sm:flex-row sm:gap-4">
        <Link href="/login" className={buttonVariants({ size: "lg" })}>
          Log in to your account
        </Link>
        <Link href="/dashboard" className={buttonVariants({ variant: "outline", size: "lg" })}>
          Go to dashboard
        </Link>
      </div>
    </div>
  )
}
