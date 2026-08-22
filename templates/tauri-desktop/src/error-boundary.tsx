import * as React from "react"

import { Alert, AlertDescription, AlertTitle } from "@amroksaleh/ui/alert"
import { Button } from "@amroksaleh/ui/button"

/**
 * A render error boundary.
 *
 * The app ships as a SINGLE bundle with no code splitting, so before this
 * existed an uncaught throw anywhere in the tree took the whole window to a
 * blank screen with no way back — the failure mode you least want in front of
 * an audience. React only surfaces render errors through a class component's
 * `componentDidCatch`/`getDerivedStateFromError`, which is why this is the
 * template's one class component.
 *
 * It catches RENDER errors only. Rejected promises inside event handlers and
 * effects do not reach it; those surface through each screen's own error
 * state (`usePluginData`, the designer's throw-and-notify adapter contract).
 */
interface Props {
  children: React.ReactNode
  /** Shown above the reset control; defaults to a generic line. */
  title?: string
  /** Invoked by "Go back" — typically a navigation away from the broken route. */
  onReset?: () => void
}

interface State {
  error: Error | null
}

export class ErrorBoundary extends React.Component<Props, State> {
  state: State = { error: null }

  static getDerivedStateFromError(error: Error): State {
    return { error }
  }

  componentDidCatch(error: Error, info: React.ErrorInfo) {
    // No telemetry sink on the device; the webview console is the one place a
    // developer can actually read this from a running build.
    console.error("[error-boundary]", error, info.componentStack)
  }

  private handleReset = () => {
    this.setState({ error: null })
    this.props.onReset?.()
  }

  render() {
    const { error } = this.state
    if (!error) return this.props.children

    return (
      <div className="flex h-full items-center justify-center p-6">
        <div className="w-full max-w-lg space-y-4">
          <Alert variant="destructive">
            <AlertTitle>{this.props.title ?? "This screen stopped responding"}</AlertTitle>
            <AlertDescription>
              {error.message || "An unexpected error occurred while rendering."}
            </AlertDescription>
          </Alert>
          <Button onClick={this.handleReset}>Go back</Button>
        </div>
      </div>
    )
  }
}
