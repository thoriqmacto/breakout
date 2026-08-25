"use client"

import Link from "next/link"
import { ArrowLeft } from "lucide-react"

import { StrategyForm } from "@/components/strategy-form"
import { Button } from "@/components/ui/button"

export default function NewStrategyPage() {
  return (
    <div className="space-y-6">
      <div className="flex items-center gap-3">
        <Link href="/dashboard/strategy">
          <Button variant="ghost" size="sm">
            <ArrowLeft className="mr-1 h-4 w-4" /> Strategies
          </Button>
        </Link>
        <h1 className="text-2xl font-semibold">New strategy</h1>
      </div>

      <StrategyForm />
    </div>
  )
}
